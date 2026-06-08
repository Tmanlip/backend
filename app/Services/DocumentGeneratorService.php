<?php

namespace App\Services;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use ZipArchive;

class DocumentGeneratorService
{
    private string $templateRoot;

    public function __construct()
    {
        $configured = trim((string) config('ai.document_template_base_path', ''));
        if ($configured === '') {
            $this->templateRoot = storage_path('app/document-generator/templates');
            return;
        }

        $this->templateRoot = $this->isAbsolutePath($configured)
            ? $configured
            : base_path($configured);
    }

    private function isAbsolutePath(string $path): bool
    {
        return preg_match('/^(?:[A-Za-z]:[\\\\\/]|\\\\\\\\|\/)/', $path) === 1;
    }

    public function health(): array
    {
        $ollamaBaseUrl = rtrim((string) config('ai.ollama_base_url', 'http://127.0.0.1:11434'), '/');

        try {
            /** @var HttpResponse $response */
            $response = Http::timeout(3)->get($ollamaBaseUrl . '/api/tags');

            if (! $response->successful()) {
                return [
                    'status' => 'degraded',
                    'backend' => 'ok',
                    'ollama' => 'error',
                    'details' => 'Ollama responded with status ' . $response->status(),
                ];
            }

            return [
                'status' => 'ok',
                'backend' => 'ok',
                'ollama' => 'ok',
            ];
        } catch (\Throwable $error) {
            return [
                'status' => 'degraded',
                'backend' => 'ok',
                'ollama' => 'unreachable',
                'details' => $error->getMessage(),
            ];
        }
    }

    public function generateAiText(string $prompt, string $language = 'english'): string
    {
        $languageInstruction = strtolower(trim($language)) === 'malay'
            ? 'Respond in formal Malay (Bahasa Melayu) using professional grammar and tone.'
            : 'Respond in formal English using professional grammar and tone.';

        $ollamaBaseUrl = rtrim((string) config('ai.ollama_base_url', 'http://127.0.0.1:11434'), '/');
        $model = (string) config('ai.document_generator_model', 'llama3');
        $timeoutSeconds = (int) config('ai.document_generator_timeout_seconds', 25);
        $timeoutSeconds = max(30, min($timeoutSeconds, 600));
        $connectTimeoutSeconds = (int) config('ai.ollama_connect_timeout_seconds', 20);
        $connectTimeoutSeconds = max(5, min($connectTimeoutSeconds, 120));
        // Keep PHP script timeout above HTTP client timeout to avoid abrupt 60s fatal errors.
        $scriptTimeoutSeconds = max(90, $timeoutSeconds + 20);
        if (function_exists('set_time_limit')) {
            @set_time_limit($scriptTimeoutSeconds);
        }
        @ini_set('max_execution_time', (string) $scriptTimeoutSeconds);
        $keepAlive = config('ai.chatbot_keep_alive', '10m');

        /** @var HttpResponse $response */
        $response = Http::retry(2, 1000, function ($exception): bool {
                return $exception instanceof ConnectionException;
            })
            ->connectTimeout($connectTimeoutSeconds)
            ->timeout($timeoutSeconds)
            ->post($ollamaBaseUrl . '/api/generate', [
            'model' => $model,
            'prompt' => $languageInstruction . "\n\n" . $prompt,
            'stream' => false,
            'keep_alive' => $keepAlive,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Ollama request failed with status ' . $response->status());
        }

        $output = (string) data_get($response->json(), 'response', '');
        if ($output === '') {
            throw new RuntimeException('Ollama response was empty.');
        }

        return $output;
    }

    public function generateLodDocx(array $formData = []): array
    {
        $variables = $this->buildLodVariables($formData);

        return $this->generateDocxFromTemplate(
            'LOD',
            [
                'LOD_Template_work.docx',
                'LOD_Template.docx',
                'LOD JENERAL TAN SRI DATO SERI ZAMROSE BIN MOHD ZAIN.docx',
            ],
            $variables,
            'LOD_Template_work.docx'
        );
    }

    public function generateWritDocx(array $formData = []): array
    {
        $signatureDataUrl = $this->sanitizeSignatureDataUrl((string) ($formData['SignedImageDataUrl'] ?? ''));
        $defendants = $this->normalizeWritDefendants($formData['Defendants'] ?? null, $formData);

        $normalizedFormData = $formData;
        if ($signatureDataUrl !== null) {
            $normalizedFormData['Signed'] = '[SIGNED_IMAGE]';
        }

        $variables = $this->buildWritVariables($normalizedFormData);

        $generated = $this->generateDocxFromTemplate(
            'WritOfSummons',
            [
                'Writ_of_Summons_Template.docx',
                'Writ_of_Summons_template.docx',
                'writ_of_summons_template.docx',
            ],
            $variables,
            'Writ_of_Summons_Template.docx'
        );

        $hasSecondDefendant = trim((string) ($variables['Defendant2Name'] ?? '')) !== ''
            || trim((string) ($variables['Defendant2NRIC'] ?? '')) !== ''
            || trim((string) ($variables['Defendant2Address'] ?? '')) !== ''
            || trim((string) ($variables['Defendant2AddressLine1'] ?? '')) !== '';

        if (! $hasSecondDefendant) {
            try {
                $generated['buffer'] = $this->stripEmptySecondDefendantLinesFromWritDocx($generated['buffer']);
            } catch (\Throwable $error) {
                logger()->warning('Writ defendant-line cleanup failed; returning generated DOCX as-is.', [
                    'message' => $error->getMessage(),
                    'exception' => $error::class,
                ]);
            }
        }

        if (count($defendants) > 2) {
            try {
                $generated['buffer'] = $this->expandAdditionalDefendantLinesInWritDocx($generated['buffer'], $defendants);
            } catch (\Throwable $error) {
                logger()->warning('Writ multi-defendant expansion failed; returning generated DOCX as-is.', [
                    'message' => $error->getMessage(),
                    'exception' => $error::class,
                ]);
            }
        }

        if ($signatureDataUrl !== null) {
            try {
                $generated['buffer'] = $this->embedSignatureImageIntoDocxBuffer($generated['buffer'], $signatureDataUrl);
            } catch (\Throwable $error) {
                logger()->warning('Writ signature embedding failed; returning text placeholder.', [
                    'message' => $error->getMessage(),
                    'exception' => $error::class,
                ]);
            }
        }

        return $generated;
    }

    private function normalizeWritDefendants($rawDefendants, array $formData = []): array
    {
        $defendants = [];

        if (is_array($rawDefendants)) {
            foreach ($rawDefendants as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $name = trim((string) ($item['name'] ?? ''));
                $nric = trim((string) ($item['nric'] ?? ''));
                $address = trim((string) ($item['address'] ?? ''));

                if ($name === '' && $nric === '' && $address === '') {
                    continue;
                }

                $defendants[] = [
                    'name' => $name,
                    'nric' => $nric,
                    'address' => $address,
                ];
            }
        }

        if ($defendants !== []) {
            return $defendants;
        }

        $fallback = [
            [
                'name' => trim((string) ($formData['Defendant1Name'] ?? $formData['DefendantName'] ?? '')),
                'nric' => trim((string) ($formData['Defendant1NRIC'] ?? $formData['DefendantNRIC'] ?? '')),
                'address' => trim((string) ($formData['Defendant1Address'] ?? $formData['DefendantAddressLine1'] ?? '')),
            ],
            [
                'name' => trim((string) ($formData['Defendant2Name'] ?? '')),
                'nric' => trim((string) ($formData['Defendant2NRIC'] ?? '')),
                'address' => trim((string) ($formData['Defendant2Address'] ?? $formData['Defendant2AddressLine1'] ?? '')),
            ],
        ];

        return array_values(array_filter($fallback, static function (array $item): bool {
            return $item['name'] !== '' || $item['nric'] !== '' || $item['address'] !== '';
        }));
    }

    private function expandAdditionalDefendantLinesInWritDocx(string $docxBuffer, array $defendants): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'aslaw-writ-expand-');
        if (! $tempFile) {
            return $docxBuffer;
        }

        if (@file_put_contents($tempFile, $docxBuffer) === false) {
            @unlink($tempFile);
            return $docxBuffer;
        }

        $zip = new ZipArchive();
        if ($zip->open($tempFile) !== true) {
            @unlink($tempFile);
            return $docxBuffer;
        }

        $documentXml = $zip->getFromName('word/document.xml');
        if (! is_string($documentXml) || trim($documentXml) === '') {
            $zip->close();
            @unlink($tempFile);
            return $docxBuffer;
        }

        $updatedXml = $this->expandAdditionalDefendantParagraphsInXml($documentXml, $defendants);
        $zip->addFromString('word/document.xml', $updatedXml);
        $zip->close();

        $updated = @file_get_contents($tempFile);
        @unlink($tempFile);

        return is_string($updated) ? $updated : $docxBuffer;
    }

    private function expandAdditionalDefendantParagraphsInXml(string $xml, array $defendants): string
    {
        if (count($defendants) <= 2) {
            return $xml;
        }

        $dom = new \DOMDocument();
        if (! @ $dom->loadXML($xml)) {
            return $xml;
        }

        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        $paragraphs = $xpath->query('//w:p');
        if (! $paragraphs) {
            return $xml;
        }

        $nameAnchor = null;
        $nricAnchor = null;
        $kepadaAnchor = null;
        $kepadaAddressAnchor = null;

        foreach ($paragraphs as $paragraph) {
            if (! ($paragraph instanceof \DOMElement)) {
                continue;
            }

            $text = $this->getWordParagraphText($paragraph, $xpath);
            if ($text === '') {
                continue;
            }

            if ($nameAnchor === null && preg_match('/^2\.\s*/u', $text) === 1) {
                $nameAnchor = $paragraph;
                continue;
            }

            if ($nricAnchor === null && preg_match('/^\(No\.\s*K\/P\s*:/iu', $text) === 1 && strpos($text, 'DEFENDAN-DEFENDAN') !== false) {
                $nricAnchor = $paragraph;
                continue;
            }

            if ($kepadaAnchor === null && preg_match('/^2\)\s*/u', $text) === 1) {
                $kepadaAnchor = $paragraph;
                continue;
            }
        }

        if ($kepadaAnchor instanceof \DOMElement) {
            $next = $kepadaAnchor->nextSibling;
            while ($next && ! ($next instanceof \DOMElement)) {
                $next = $next->nextSibling;
            }

            if ($next instanceof \DOMElement && $next->localName === 'p') {
                $kepadaAddressAnchor = $next;
            }
        }

        if (! ($nameAnchor instanceof \DOMElement) || ! ($nricAnchor instanceof \DOMElement) || ! ($kepadaAnchor instanceof \DOMElement)) {
            return $xml;
        }

        $lastNricParagraph = $nricAnchor;
        $lastKepadaAddressParagraph = $kepadaAddressAnchor instanceof \DOMElement ? $kepadaAddressAnchor : $kepadaAnchor;

        for ($i = 2; $i < count($defendants); $i++) {
            $number = $i + 1;
            $item = $defendants[$i];

            $nameParagraph = $nameAnchor->cloneNode(true);
            $this->setWordParagraphText($nameParagraph, $number . '. ' . (string) ($item['name'] ?? ''), $xpath);

            $nricParagraph = $nricAnchor->cloneNode(true);
            $this->setWordParagraphText(
                $nricParagraph,
                '(No. K/P : ' . (string) ($item['nric'] ?? '') . ')' . ($i === count($defendants) - 1 ? ' ...DEFENDAN-DEFENDAN' : ''),
                $xpath
            );

            $lastNricParagraph->parentNode?->insertBefore($nameParagraph, $lastNricParagraph->nextSibling);
            $nameParagraph->parentNode?->insertBefore($nricParagraph, $nameParagraph->nextSibling);
            $lastNricParagraph = $nricParagraph;

            $kepadaNumberParagraph = $kepadaAnchor->cloneNode(true);
            $this->setWordParagraphText($kepadaNumberParagraph, $number . ') ' . (string) ($item['name'] ?? ''), $xpath);

            $addressTemplate = $kepadaAddressAnchor instanceof \DOMElement ? $kepadaAddressAnchor : $kepadaAnchor;
            $kepadaAddressParagraph = $addressTemplate->cloneNode(true);
            $this->setWordParagraphText($kepadaAddressParagraph, (string) ($item['address'] ?? ''), $xpath);

            $lastKepadaAddressParagraph->parentNode?->insertBefore($kepadaNumberParagraph, $lastKepadaAddressParagraph->nextSibling);
            $kepadaNumberParagraph->parentNode?->insertBefore($kepadaAddressParagraph, $kepadaNumberParagraph->nextSibling);
            $lastKepadaAddressParagraph = $kepadaAddressParagraph;
        }

        // Keep DEFENDAN-DEFENDAN only on the last defendant NRIC line.
        $this->setWordParagraphText(
            $nricAnchor,
            preg_replace('/\s*\.\.\.DEFENDAN-DEFENDAN\s*$/u', '', $this->getWordParagraphText($nricAnchor, $xpath)) ?: $this->getWordParagraphText($nricAnchor, $xpath),
            $xpath
        );

        return $dom->saveXML() ?: $xml;
    }

    private function getWordParagraphText(\DOMElement $paragraph, \DOMXPath $xpath): string
    {
        $textNodes = $xpath->query('.//w:t', $paragraph);
        if (! $textNodes) {
            return '';
        }

        $value = '';
        foreach ($textNodes as $textNode) {
            $value .= (string) $textNode->textContent;
        }

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    private function setWordParagraphText(\DOMElement $paragraph, string $text, \DOMXPath $xpath): void
    {
        $textNodes = $xpath->query('.//w:t', $paragraph);
        if (! $textNodes || $textNodes->length === 0) {
            return;
        }

        $textNodes->item(0)->textContent = $text;
        for ($i = 1; $i < $textNodes->length; $i++) {
            $textNodes->item($i)->textContent = '';
        }
    }

    private function stripEmptySecondDefendantLinesFromWritDocx(string $docxBuffer): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'aslaw-writ-clean-');
        if (! $tempFile) {
            return $docxBuffer;
        }

        if (@file_put_contents($tempFile, $docxBuffer) === false) {
            @unlink($tempFile);
            return $docxBuffer;
        }

        $zip = new ZipArchive();
        if ($zip->open($tempFile) !== true) {
            @unlink($tempFile);
            return $docxBuffer;
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entryName = $zip->getNameIndex($i);
            if (! is_string($entryName)) {
                continue;
            }

            if (! preg_match('/(^|\/)word\/(document|header[0-9]+|footer[0-9]+)\.xml$/i', $entryName)) {
                continue;
            }

            $xml = $zip->getFromName($entryName);
            if (! is_string($xml) || trim($xml) === '') {
                continue;
            }

            $cleanedXml = $this->stripEmptySecondDefendantParagraphsFromXml($xml);
            $zip->addFromString($entryName, $cleanedXml);
        }

        $zip->close();

        $updated = @file_get_contents($tempFile);
        @unlink($tempFile);

        return is_string($updated) ? $updated : $docxBuffer;
    }

    private function stripEmptySecondDefendantParagraphsFromXml(string $xml): string
    {
        $dom = new \DOMDocument();
        if (! @ $dom->loadXML($xml)) {
            return $xml;
        }

        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        $paragraphs = $xpath->query('//w:p');
        if (! $paragraphs) {
            return $xml;
        }

        $toRemove = [];
        foreach ($paragraphs as $paragraph) {
            if (! ($paragraph instanceof \DOMElement)) {
                continue;
            }

            $textNodes = $xpath->query('.//w:t', $paragraph);
            if (! $textNodes) {
                continue;
            }

            $text = '';
            foreach ($textNodes as $textNode) {
                $text .= (string) $textNode->textContent;
            }

            $normalized = preg_replace('/\s+/u', ' ', trim($text));
            if (! is_string($normalized) || $normalized === '') {
                continue;
            }

            $isOrphanNumberedLine = preg_match('/^2[\.)]\s*$/u', $normalized) === 1;
            $isOrphanNricLine = preg_match('/^\(No\.\s*K\/P\s*:\s*\)\s*(\.{3,}\s*)?DEFENDAN-DEFENDAN$/iu', $normalized) === 1;

            if ($isOrphanNumberedLine || $isOrphanNricLine) {
                $toRemove[] = $paragraph;
            }
        }

        foreach ($toRemove as $paragraph) {
            $parent = $paragraph->parentNode;
            if ($parent instanceof \DOMNode) {
                $parent->removeChild($paragraph);
            }
        }

        return $dom->saveXML() ?: $xml;
    }

    public function generateInvoiceDocx(array $formData = []): array
    {
        $variables = $this->buildInvoiceVariables($formData);
        $html = $this->buildInvoiceHtmlFromVariables($variables);

        $safeNum = preg_replace('/[^A-Za-z0-9\-_]/', '_', (string) ($variables['invoice_number'] ?? 'invoice'));

        // Active approach: build invoice DOCX from the HTML template defined in buildInvoiceHtmlFromVariables().
        // This dynamically generates professional invoice documents with styling and formatting.
        return $this->createDocxFromHtml($html, $safeNum . '.docx');

        /*
         * Alternative approach (legacy, commented):
         * Use a static DOCX template file from storage/app/document-generator/templates/invoice.
         * This would load an existing template and populate placeholders {{key}}.
         *
         * return $this->generateDocxFromTemplate(
         *     'invoice',
         *     [
         *         'Invoice_Template.docx',
         *         'invoice_template.docx',
         *     ],
         *     $variables,
         *     $safeNum . '.docx'
         * );
         */
    }

    public function generateInvoicePdf(array $formData = []): array
    {
        $v = $this->buildInvoiceVariables($formData);

        // Render the invoice HTML template into a temporary DOCX bundle and
        // convert it to PDF with LibreOffice so the endpoint stays HTML-driven
        // without depending on Dompdf/GD.
        $html = $this->buildInvoiceHtmlFromVariables($v);

        $pdfBuffer = null;
        $conversionErrorMessage = null;

        try {
            $docx = $this->createDocxFromHtml($html, 'invoice-template.docx');
            $pdfBuffer = $this->convertDocxBufferToPdf($docx['buffer']);
        } catch (\Throwable $conversionError) {
            $conversionErrorMessage = $conversionError->getMessage();

            logger()->warning('Invoice PDF LibreOffice conversion failed, falling back to Dompdf.', [
                'message' => $conversionErrorMessage,
                'exception' => $conversionError::class,
            ]);
        }

        if (!is_string($pdfBuffer) || $pdfBuffer === '') {
            try {
                $pdfBuffer = $this->renderInvoiceHtmlToPdf($html);
            } catch (\Throwable $fallbackError) {
                throw new RuntimeException(
                    'Unable to generate invoice PDF. LibreOffice conversion failed'
                    . ($conversionErrorMessage ? ': ' . $conversionErrorMessage : '.')
                    . ' Dompdf fallback failed: ' . $fallbackError->getMessage()
                );
            }
        }

        $safeNum = preg_replace('/[^A-Za-z0-9\-_]/', '_', (string)($v['invoice_number'] ?? 'invoice'));

        return [
            'buffer' => $pdfBuffer,
            'filename' => $safeNum . '.pdf',
        ];

        /*
         * LEGACY APPROACH (NOT USED):
         * Generate DOCX from template, then convert to PDF via LibreOffice.
         * This is commented out because:
         * - Requires LibreOffice installation on server
         * - Slower than Dompdf
         * - Additional system dependency
         *
         * $docx = $this->generateInvoiceDocx($formData);
         * $html = $this->renderDocxBufferAsHtml($docx['buffer']);
         *
         * if (!is_string($html) || trim($html) === '') {
         *     $html = $this->buildInvoiceHtmlFromVariables($v);
         * }
         */
    }

    private function buildInvoiceHtmlFromVariables(array $v): string
    {
        $fmt = fn($n) => is_numeric($n) && (string)$n !== '' ? 'RM ' . number_format((float)$n, 2) : '-';
        $esc = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $choose = function (string $englishText, string $malayText) use ($v): string {
            return $this->resolveInvoiceLanguage($v) === 'malay' ? $malayText : $englishText;
        };

        $stageValue = strtolower(trim((string) ($v['payment_stage'] ?? 'initial')));
        $stage = match ($stageValue) {
            'initial' => $choose('Initial', 'Permulaan'),
            'first' => $choose('First', 'Pertama'),
            'second' => $choose('Second', 'Kedua'),
            'third' => $choose('Third', 'Ketiga'),
            'final' => $choose('Final', 'Akhir'),
            default => ucfirst($stageValue !== '' ? $stageValue : 'initial'),
        };
        $invoiceNum = $esc($v['invoice_number'] ?? '');
        $clientName = $esc($v['client_name'] ?? '');
        $caseTitle  = $esc($v['case_title'] ?? '');
        $typeOfWork = $esc($v['type_of_work'] ?? '');
        $issueDate  = $esc($v['issue_date'] ?? '');
        $dueDateRaw = trim((string) ($v['due_date'] ?? ''));
        $dueDateLabel = $choose('Due', 'Tarikh Akhir');
        $dueDate = $esc($dueDateRaw !== '' ? $dueDateLabel . ': ' . $dueDateRaw : '-');
        $dueDateValue = $esc($dueDateRaw !== '' ? $dueDateRaw : '-');
        $expected   = $fmt($v['expected_amount'] ?? '');
        $paid       = $fmt($v['paid_amount'] ?? '');
        $tax        = is_numeric($v['tax'] ?? '') && (string)($v['tax'] ?? '') !== '' ? $esc($v['tax']) . '%' : '-';
        $discount   = is_numeric($v['discount'] ?? '') && (string)($v['discount'] ?? '') !== '' ? $esc($v['discount']) . '%' : '-';
        $total      = $fmt($v['total_amount'] ?? '');
        $typeOfWorkBalance = $fmt($v['balance'] ?? '');
        $phaseBalance = $fmt($v['phase_balance'] ?? '');
        $invoiceTitle = $choose('INVOICE', 'INVOIS');
        $paymentLabel = $choose('Payment', 'Bayaran');
        $issuedLabel = $choose('Issued', 'Dikeluarkan');
        $billedToLabel = $choose('Billed To', 'Dibil Kepada');
        $matterLabel = $choose('Matter', 'Perkara');
        $typeOfWorkLabel = $choose('Type of Work', 'Jenis Kerja');
        $expectedAmountLabel = $choose('Expected Amount', 'Jumlah Dijangka');
        $paidAmountLabel = $choose('Amount Paid', 'Jumlah Dibayar');
        $typeOfWorkBalanceLabel = $choose('Type of Work Balance', 'Baki Jenis Kerja');
        $phaseBalanceLabel = $choose('Phase Balance', 'Baki Fasa');
        $taxLabel = $choose('Tax', 'Cukai');
        $discountLabel = $choose('Discount', 'Diskaun');
        $totalLabel = $choose('Total', 'Jumlah Keseluruhan');
        $amountDueLabel = $choose('Amount Due', 'Jumlah Perlu Dibayar');
        $brandTagline = $choose('Professional Legal Invoice', 'Invois Undang-undang Profesional');
        $brandContactLine = $esc((string) ($v['brand_contact_line'] ?? 'admin@aslaw.com.my | +60 12-345 6789'));
        $footer = $choose(
            'This is a computer-generated invoice. No signature is required.',
            'Ini ialah invois yang dijana oleh komputer. Tandatangan tidak diperlukan.'
        );

        $logoDataUri = $this->buildInvoiceLogoDataUri();
        $brandLogoHtml = $logoDataUri !== null
            ? '<img src="' . $esc($logoDataUri) . '" alt="ASLAW" width="320" height="56" style="display:block;width:320px;height:56px;" />'
            : '<span class="brand-text">ASLAW</span>';

        return $this->renderInvoiceTemplate([
            '{{invoiceTitle}}' => $invoiceTitle,
            '{{stage}}' => $stage,
            '{{paymentLabel}}' => $paymentLabel,
            '{{brandLogoHtml}}' => $brandLogoHtml,
            '{{brandTagline}}' => $brandTagline,
            '{{brandContactLine}}' => $brandContactLine,
            '{{invoiceNum}}' => $invoiceNum,
            '{{issuedLabel}}' => $issuedLabel,
            '{{issueDate}}' => $issueDate,
            '{{dueDate}}' => $dueDate,
            '{{dueDateValue}}' => $dueDateValue,
            '{{billedToLabel}}' => $billedToLabel,
            '{{clientName}}' => $clientName,
            '{{matterLabel}}' => $matterLabel,
            '{{caseTitle}}' => $caseTitle,
            '{{typeOfWorkLabel}}' => $typeOfWorkLabel,
            '{{typeOfWork}}' => $typeOfWork,
            '{{expectedAmountLabel}}' => $expectedAmountLabel,
            '{{expected}}' => $expected,
            '{{paidAmountLabel}}' => $paidAmountLabel,
            '{{paid}}' => $paid,
            '{{typeOfWorkBalanceLabel}}' => $typeOfWorkBalanceLabel,
            '{{typeOfWorkBalance}}' => $typeOfWorkBalance,
            '{{phaseBalanceLabel}}' => $phaseBalanceLabel,
            '{{phaseBalance}}' => $phaseBalance,
            '{{taxLabel}}' => $taxLabel,
            '{{tax}}' => $tax,
            '{{discountLabel}}' => $discountLabel,
            '{{discount}}' => $discount,
            '{{totalLabel}}' => $totalLabel,
            '{{total}}' => $total,
            '{{amountDueLabel}}' => $amountDueLabel,
            '{{footer}}' => $footer,
        ]);
    }

    private function renderInvoiceTemplate(array $placeholders): string
    {
        $templatePath = resource_path('document-generator/templates/invoice.html');
        $template = @file_get_contents($templatePath);

        if (!is_string($template) || trim($template) === '') {
            throw new RuntimeException('Invoice HTML template not found or empty at ' . $templatePath);
        }

        return strtr($template, $placeholders);
    }

    private function buildInvoiceLogoDataUri(): ?string
    {
        $logoPath = public_path('images/aslaw-logo.png');

        if (!is_file($logoPath) || !is_readable($logoPath)) {
            return null;
        }

        $contents = @file_get_contents($logoPath);
        if (!is_string($contents) || $contents === '') {
            return null;
        }

        return 'data:image/png;base64,' . base64_encode($contents);
    }

    private function renderInvoiceHtmlToPdf(string $html): string
    {
        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $output = $dompdf->output();
        if (!is_string($output) || $output === '') {
            throw new RuntimeException('Dompdf returned an empty PDF output.');
        }

        return $output;
    }

    private function resolveInvoiceLanguage(array $variables): string
    {
        $language = strtolower(trim((string) ($variables['language'] ?? 'english')));

        return $language === 'malay' ? 'malay' : 'english';
    }

        private function createDocxFromHtml(string $html, string $downloadName): array
        {
                $tempFile = tempnam(sys_get_temp_dir(), 'aslaw-docx-html-');
                if (! $tempFile) {
                        throw new RuntimeException('Unable to allocate temporary DOCX file.');
                }

                $zip = new ZipArchive();
                if ($zip->open($tempFile, ZipArchive::OVERWRITE) !== true) {
                        @unlink($tempFile);
                        throw new RuntimeException('Unable to create DOCX archive.');
                }

                $contentTypes = <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
    <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
    <Default Extension="xml" ContentType="application/xml"/>
    <Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>
    <Override PartName="/word/afchunk.html" ContentType="text/html"/>
</Types>
XML;

                $rels = <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>
</Relationships>
XML;

                $documentXml = <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:wpc="http://schemas.microsoft.com/office/word/2010/wordprocessingCanvas"
        xmlns:mc="http://schemas.openxmlformats.org/markup-compatibility/2006"
        xmlns:o="urn:schemas-microsoft-com:office:office"
        xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"
        xmlns:m="http://schemas.openxmlformats.org/officeDocument/2006/math"
        xmlns:v="urn:schemas-microsoft-com:vml"
        xmlns:wp14="http://schemas.microsoft.com/office/word/2010/wordprocessingDrawing"
        xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"
        xmlns:w10="urn:schemas-microsoft-com:office:word"
        xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"
        xmlns:w14="http://schemas.microsoft.com/office/word/2010/wordml"
        xmlns:wpg="http://schemas.microsoft.com/office/word/2010/wordprocessingGroup"
        xmlns:wpi="http://schemas.microsoft.com/office/word/2010/wordprocessingInk"
        xmlns:wne="http://schemas.microsoft.com/office/word/2006/wordml"
        xmlns:wps="http://schemas.microsoft.com/office/word/2010/wordprocessingShape"
        mc:Ignorable="w14 wp14">
    <w:body>
        <w:altChunk r:id="htmlChunk"/>
        <w:sectPr>
            <w:pgSz w:w="11906" w:h="16838"/>
            <w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440" w:header="720" w:footer="720" w:gutter="0"/>
        </w:sectPr>
    </w:body>
</w:document>
XML;

                $documentRels = <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="htmlChunk" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/aFChunk" Target="afchunk.html"/>
</Relationships>
XML;

                $zip->addFromString('[Content_Types].xml', $contentTypes);
                $zip->addFromString('_rels/.rels', $rels);
                $zip->addFromString('word/document.xml', $documentXml);
                $zip->addFromString('word/_rels/document.xml.rels', $documentRels);
                $zip->addFromString('word/afchunk.html', $html);
                $zip->close();

                $buffer = file_get_contents($tempFile);
                @unlink($tempFile);

                if (! is_string($buffer)) {
                        throw new RuntimeException('Unable to read generated HTML-based DOCX.');
                }

                return [
                        'buffer' => $buffer,
                        'filename' => $downloadName,
                ];
        }

    private function renderDocxBufferAsHtml(string $docxBuffer): ?string
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'aslaw-inv-docx-');
        if (! $tmpFile) {
            return null;
        }

        $docxPath = $tmpFile . '.docx';
        @unlink($tmpFile);

        if (@file_put_contents($docxPath, $docxBuffer) === false) {
            @unlink($docxPath);
            return null;
        }

        $zip = new ZipArchive();
        if ($zip->open($docxPath) !== true) {
            @unlink($docxPath);
            return null;
        }

        $documentXml = $zip->getFromName('word/document.xml');
        $zip->close();
        @unlink($docxPath);

        if (! is_string($documentXml) || trim($documentXml) === '') {
            return null;
        }

        $dom = new \DOMDocument();
        if (! @$dom->loadXML($documentXml)) {
            return null;
        }

        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        $bodyNodes = $xpath->query('/w:document/w:body/*');
        if (! $bodyNodes) {
            return null;
        }

        $parts = [];
        foreach ($bodyNodes as $node) {
            if (! $node instanceof \DOMElement) {
                continue;
            }

            if ($node->localName === 'p') {
                $text = trim($this->extractWordNodeText($node, $xpath));
                if ($text !== '') {
                    $parts[] = '<p>' . nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) . '</p>';
                }
                continue;
            }

            if ($node->localName === 'tbl') {
                $parts[] = $this->renderWordTableNodeToHtml($node, $xpath);
            }
        }

        if (count($parts) === 0) {
            return null;
        }

        return '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>'
            . 'body{font-family:DejaVu Sans,Arial,sans-serif;font-size:12px;color:#1f2937;padding:28px;}'
            . 'p{margin:0 0 10px;line-height:1.55;}'
            . 'table{width:100%;border-collapse:collapse;margin:12px 0;}'
            . 'td,th{border:1px solid #d1d5db;padding:8px 10px;vertical-align:top;}'
            . '</style></head><body>'
            . implode('', $parts)
            . '</body></html>';
    }

    private function renderWordTableNodeToHtml(\DOMElement $tableNode, \DOMXPath $xpath): string
    {
        $rows = $xpath->query('./w:tr', $tableNode);
        if (! $rows) {
            return '';
        }

        $htmlRows = [];
        foreach ($rows as $rowNode) {
            if (! $rowNode instanceof \DOMElement) {
                continue;
            }

            $cells = $xpath->query('./w:tc', $rowNode);
            if (! $cells) {
                continue;
            }

            $htmlCells = [];
            foreach ($cells as $cellNode) {
                if (! $cellNode instanceof \DOMElement) {
                    continue;
                }

                $text = trim($this->extractWordNodeText($cellNode, $xpath));
                $htmlCells[] = '<td>' . nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) . '</td>';
            }

            if (count($htmlCells) > 0) {
                $htmlRows[] = '<tr>' . implode('', $htmlCells) . '</tr>';
            }
        }

        return count($htmlRows) > 0 ? '<table>' . implode('', $htmlRows) . '</table>' : '';
    }

    private function extractWordNodeText(\DOMNode $node, \DOMXPath $xpath): string
    {
        $fragments = $xpath->query('.//w:t|.//w:tab|.//w:br', $node);
        if (! $fragments) {
            return '';
        }

        $text = '';
        foreach ($fragments as $fragment) {
            if (! $fragment instanceof \DOMElement) {
                continue;
            }

            if ($fragment->localName === 't') {
                $text .= (string) $fragment->textContent;
            } elseif ($fragment->localName === 'tab') {
                $text .= "\t";
            } elseif ($fragment->localName === 'br') {
                $text .= "\n";
            }
        }

        return $text;
    }

    public function generateLodPdf(array $formData = []): array
    {
        $signatureDataUrl = $this->sanitizeSignatureDataUrl((string) ($formData['SignedImageDataUrl'] ?? ''));
        $docx = null;
        if ($signatureDataUrl !== null) {
            $lodFormData = $formData;
            $lodFormData['Signed'] = '[SIGNED_IMAGE]';
            $docxWithMarker = $this->generateLodDocx($lodFormData);

            try {
                $docxWithSignature = $this->embedSignatureImageIntoDocxBuffer($docxWithMarker['buffer'], $signatureDataUrl);
                $pdfWithSignature = $this->convertDocxBufferToPdf($docxWithSignature);

                return [
                    'buffer' => $pdfWithSignature,
                    'filename' => 'LOD_Template_work.pdf',
                ];
            } catch (\Throwable $signatureError) {
                logger()->warning('LOD signature embedding via DOCX failed; trying fallback path.', [
                    'message' => $signatureError->getMessage(),
                    'exception' => $signatureError::class,
                ]);

                if (extension_loaded('gd')) {
                    try {
                        $html = $this->renderDocxBufferAsHtml($docxWithMarker['buffer']);
                        if (is_string($html) && trim($html) !== '') {
                            $htmlWithSignature = $this->injectSignatureIntoLodHtml($html, $signatureDataUrl);
                            $pdfWithSignature = $this->renderInvoiceHtmlToPdf($htmlWithSignature);

                            return [
                                'buffer' => $pdfWithSignature,
                                'filename' => 'LOD_Template_work.pdf',
                            ];
                        }
                    } catch (\Throwable $htmlFallbackError) {
                        logger()->warning('LOD signature HTML fallback failed.', [
                            'message' => $htmlFallbackError->getMessage(),
                            'exception' => $htmlFallbackError::class,
                        ]);
                    }
                } else {
                    logger()->warning('LOD signature image was provided but GD is unavailable; HTML image fallback skipped.');
                }
            }
        }

        $docx = $this->generateLodDocx($formData);

        $pdfBuffer = null;
        $conversionErrorMessage = null;

        try {
            $pdfBuffer = $this->convertDocxBufferToPdf($docx['buffer']);
        } catch (\Throwable $conversionError) {
            $conversionErrorMessage = $conversionError->getMessage();

            logger()->warning('LOD PDF LibreOffice conversion failed, falling back to Dompdf.', [
                'message' => $conversionErrorMessage,
                'exception' => $conversionError::class,
            ]);
        }

        if (! is_string($pdfBuffer) || $pdfBuffer === '') {
            $html = $this->renderDocxBufferAsHtml($docx['buffer']);
            if (! is_string($html) || trim($html) === '') {
                throw new RuntimeException(
                    'Unable to generate LOD PDF. LibreOffice conversion failed'
                    . ($conversionErrorMessage ? ': ' . $conversionErrorMessage : '.')
                    . ' Docx-to-HTML fallback produced empty content.'
                );
            }

            $pdfBuffer = $this->renderInvoiceHtmlToPdf($html);
        }

        return [
            'buffer' => $pdfBuffer,
            'filename' => 'LOD_Template_work.pdf',
        ];
    }

    public function generateWritPdf(array $formData = []): array
    {
        $docx = $this->generateWritDocx($formData);

        $pdfBuffer = null;
        $conversionErrorMessage = null;

        try {
            $pdfBuffer = $this->convertDocxBufferToPdf($docx['buffer']);
        } catch (\Throwable $conversionError) {
            $conversionErrorMessage = $conversionError->getMessage();

            logger()->warning('Writ PDF LibreOffice conversion failed, falling back to Dompdf.', [
                'message' => $conversionErrorMessage,
                'exception' => $conversionError::class,
            ]);
        }

        if (! is_string($pdfBuffer) || $pdfBuffer === '') {
            $html = $this->renderDocxBufferAsHtml($docx['buffer']);
            if (! is_string($html) || trim($html) === '') {
                throw new RuntimeException(
                    'Unable to generate Writ PDF. LibreOffice conversion failed'
                    . ($conversionErrorMessage ? ': ' . $conversionErrorMessage : '.')
                    . ' Docx-to-HTML fallback produced empty content.'
                );
            }

            $pdfBuffer = $this->renderInvoiceHtmlToPdf($html);
        }

        return [
            'buffer' => $pdfBuffer,
            'filename' => 'Writ_of_Summons_Template.pdf',
        ];
    }

    private function sanitizeSignatureDataUrl(string $value): ?string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        return preg_match('~^data:image/(?:png|jpe?g);base64,[A-Za-z0-9+/=\r\n]+$~i', $trimmed) === 1
            ? $trimmed
            : null;
    }

    private function embedSignatureImageIntoDocxBuffer(string $docxBuffer, string $signatureDataUrl): string
    {
        if ($docxBuffer === '') {
            throw new RuntimeException('DOCX buffer is empty; cannot embed signature image.');
        }

        if (! preg_match('~^data:image/(png|jpe?g);base64,(.+)$~is', trim($signatureDataUrl), $matches)) {
            throw new RuntimeException('Invalid signature data URL format.');
        }

        $imageType = strtolower((string) ($matches[1] ?? 'png'));
        $base64Payload = preg_replace('/\s+/', '', (string) ($matches[2] ?? ''));
        $imageBinary = base64_decode($base64Payload, true);

        if (! is_string($imageBinary) || $imageBinary === '') {
            throw new RuntimeException('Unable to decode signature image payload.');
        }

        $extension = $imageType === 'jpeg' || $imageType === 'jpg' ? 'jpg' : 'png';
        $imageFileName = 'signed-image-' . substr(sha1($imageBinary), 0, 12) . '.' . $extension;
        $imagePath = 'word/media/' . $imageFileName;

        $tempFile = tempnam(sys_get_temp_dir(), 'aslaw-lod-sign-');
        if (! $tempFile) {
            throw new RuntimeException('Unable to allocate temporary DOCX file for signature embedding.');
        }

        file_put_contents($tempFile, $docxBuffer);

        $zip = new ZipArchive();
        if ($zip->open($tempFile) !== true) {
            @unlink($tempFile);
            throw new RuntimeException('Unable to open DOCX archive for signature embedding.');
        }

        $zip->addFromString($imagePath, $imageBinary);

        $relsPath = 'word/_rels/document.xml.rels';
        $relsXml = $zip->getFromName($relsPath);
        if (! is_string($relsXml) || trim($relsXml) === '') {
            $zip->close();
            @unlink($tempFile);
            throw new RuntimeException('DOCX relationships file is missing; cannot attach signature image.');
        }

        preg_match_all('/Id="rId(\d+)"/i', $relsXml, $relMatches);
        $maxRelId = 0;
        foreach (($relMatches[1] ?? []) as $idText) {
            $idValue = (int) $idText;
            if ($idValue > $maxRelId) {
                $maxRelId = $idValue;
            }
        }

        $nextRelId = 'rId' . ($maxRelId + 1);
        $relationshipXml = '<Relationship Id="' . $nextRelId . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/' . $imageFileName . '"/>';
        $updatedRelsXml = preg_replace('/<\/Relationships>\s*$/i', $relationshipXml . '</Relationships>', $relsXml, 1);
        if (! is_string($updatedRelsXml) || $updatedRelsXml === '') {
            $zip->close();
            @unlink($tempFile);
            throw new RuntimeException('Unable to update DOCX relationships with signature image.');
        }

        $zip->addFromString($relsPath, $updatedRelsXml);

        $documentPath = 'word/document.xml';
        $documentXml = $zip->getFromName($documentPath);
        if (! is_string($documentXml) || trim($documentXml) === '') {
            $zip->close();
            @unlink($tempFile);
            throw new RuntimeException('DOCX document.xml is missing; cannot place signature image.');
        }

        $drawingRunXml = $this->buildWordSignatureDrawingRunXml($nextRelId);
        $markerWasReplaced = false;
        $updatedDocumentXml = preg_replace_callback(
            '~<w:t([^>]*)>(.*?)</w:t>~s',
            function (array $matches) use (&$markerWasReplaced, $drawingRunXml): string {
                if ($markerWasReplaced) {
                    return $matches[0];
                }

                $attributes = (string) ($matches[1] ?? '');
                $text = (string) ($matches[2] ?? '');

                $marker = null;
                if (strpos($text, '[SIGNED_IMAGE]') !== false) {
                    $marker = '[SIGNED_IMAGE]';
                } elseif (strpos($text, '[SIGNED IMAGE]') !== false) {
                    $marker = '[SIGNED IMAGE]';
                } elseif (strpos($text, '{{Signed}}') !== false) {
                    $marker = '{{Signed}}';
                }

                if ($marker === null) {
                    return $matches[0];
                }

                $parts = explode($marker, $text, 2);
                $before = (string) ($parts[0] ?? '');
                $after = (string) ($parts[1] ?? '');

                $fragment = '';
                if ($before !== '') {
                    $fragment .= '<w:t' . $attributes . '>' . $before . '</w:t>';
                }

                $fragment .= '</w:r>' . $drawingRunXml . '<w:r>';

                if ($after !== '') {
                    $fragment .= '<w:t' . $attributes . '>' . $after . '</w:t>';
                }

                $markerWasReplaced = true;

                return $fragment;
            },
            $documentXml,
            1
        );

        if ((! is_string($updatedDocumentXml) || ! $markerWasReplaced) && strpos($documentXml, '[SIGNED_IMAGE]') !== false) {
            $updatedDocumentXml = str_replace(
                '[SIGNED_IMAGE]',
                '</w:t></w:r>' . $drawingRunXml . '<w:r><w:t>',
                $documentXml,
                $replacementCount
            );
            $markerWasReplaced = is_string($updatedDocumentXml) && ($replacementCount ?? 0) > 0;
        }

        if ((! is_string($updatedDocumentXml) || ! $markerWasReplaced) && strpos($documentXml, '[SIGNED IMAGE]') !== false) {
            $updatedDocumentXml = str_replace(
                '[SIGNED IMAGE]',
                '</w:t></w:r>' . $drawingRunXml . '<w:r><w:t>',
                $documentXml,
                $replacementCount
            );
            $markerWasReplaced = is_string($updatedDocumentXml) && ($replacementCount ?? 0) > 0;
        }

        if (! is_string($updatedDocumentXml) || ! $markerWasReplaced) {
            $zip->close();
            @unlink($tempFile);
            throw new RuntimeException('Signature placeholder marker was not found in DOCX content.');
        }

        $zip->addFromString($documentPath, $updatedDocumentXml);
        $zip->close();

        $updatedBuffer = file_get_contents($tempFile);
        @unlink($tempFile);

        if (! is_string($updatedBuffer) || $updatedBuffer === '') {
            throw new RuntimeException('Unable to read updated DOCX buffer after signature embedding.');
        }

        return $updatedBuffer;
    }

    private function buildWordSignatureDrawingRunXml(string $relationshipId): string
    {
        $widthEmu = 2_095_500;
        $heightEmu = 857_250;

        return '<w:r><w:drawing>'
            . '<wp:inline distT="0" distB="0" distL="0" distR="0" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing">'
            . '<wp:extent cx="' . $widthEmu . '" cy="' . $heightEmu . '"/>'
            . '<wp:docPr id="9001" name="SignatureImage"/>'
            . '<a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">'
            . '<a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            . '<pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            . '<pic:nvPicPr><pic:cNvPr id="0" name="Signature"/><pic:cNvPicPr/></pic:nvPicPr>'
            . '<pic:blipFill><a:blip r:embed="' . $relationshipId . '" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
            . '<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $widthEmu . '" cy="' . $heightEmu . '"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr>'
            . '</pic:pic>'
            . '</a:graphicData>'
            . '</a:graphic>'
            . '</wp:inline>'
            . '</w:drawing></w:r>';
    }

    private function injectSignatureIntoLodHtml(string $html, string $signatureDataUrl): string
    {
        $signatureImg = '<img src="' . htmlspecialchars($signatureDataUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" alt="Signature" style="display:inline-block;max-width:220px;max-height:90px;vertical-align:middle;" />';

        if (strpos($html, '[SIGNED_IMAGE]') !== false) {
            return str_replace('[SIGNED_IMAGE]', $signatureImg, $html);
        }

        // Fallback in case placeholder is altered by template edits.
        if (strpos($html, '[SIGNED IMAGE]') !== false) {
            return str_replace('[SIGNED IMAGE]', $signatureImg, $html);
        }

        return $html;
    }

    public function syncLodWorkbook(array $formData = []): string
    {
        $variables = $this->buildLodVariables($formData);
        $columnOrder = [
            'Date',
            'YourCompanyName',
            'YourCompanyAddressLine1',
            'YourCompanyAddressLine2',
            'YourCompanyPhone',
            'YourCompanyEmail',
            'RecipientName',
            'RecipientCompanyName',
            'RecipientAddressLine1',
            'RecipientAddressLine2',
            'RecipientSalutation',
            'Reference',
            'Currency',
            'AmountDue',
            'GoodsOrServices',
            'AgreementType',
            'AgreementDate',
            'InvoiceNumber',
            'DueDate',
            'ReminderDates',
            'PaymentWindowDays',
            'FinalPaymentDate',
            'PaymentInstructions',
            'RemittanceEmail',
            'ContactPerson',
            'ContactPhone',
            'ContactEmail',
            'DisputeWindowDays',
            'YourSignerName',
            'YourSignerTitle',
        ];

        return $this->syncWorkbook(
            'LOD',
            ['LOD_Data.xlsx'],
            'LOD_Data_filled.xlsx',
            $columnOrder,
            $variables
        );
    }

    public function syncWritWorkbook(array $formData = []): string
    {
        $variables = $this->buildWritVariables($formData);
        $columnOrder = [
            'Date',
            'CourtName',
            'CourtLocation',
            'CaseNumber',
            'PlaintiffName',
            'PlaintiffNRIC',
            'PlaintiffAddressLine1',
            'PlaintiffAddressLine2',
            'DefendantName',
            'DefendantNRIC',
            'DefendantAddressLine1',
            'DefendantAddressLine2',
            'ClaimAmount',
            'Currency',
            'ClaimDescription',
            'ContractDate',
            'BreachDetails',
            'InterestRate',
            'CostsAmount',
            'LawyerName',
            'LawFirmName',
            'LawFirmAddress',
            'LawyerPhone',
            'LawyerEmail',
            'AppearanceDays',
            'HearingDate',
            'CourtSealReference',
        ];

        return $this->syncWorkbook(
            'WritOfSummons',
            ['Writ_of_Summons_Data_new.xlsx', 'Writ_of_Summons_Data.xlsx', 'Writ_of_Summons_Data_v1.xlsx'],
            'Writ_of_Summons_Data_filled.xlsx',
            $columnOrder,
            $variables
        );
    }

    private function generateDocxFromTemplate(string $folder, array $candidates, array $variables, string $downloadName): array
    {
        $templatePath = $this->resolveTemplatePath($folder, $candidates);
        $tempFile = tempnam(sys_get_temp_dir(), 'aslaw-docx-');

        if (! $tempFile) {
            throw new RuntimeException('Unable to allocate temporary file.');
        }

        copy($templatePath, $tempFile);

        $zip = new ZipArchive();
        if ($zip->open($tempFile) !== true) {
            @unlink($tempFile);
            throw new RuntimeException('Unable to open DOCX template archive.');
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entryName = $zip->getNameIndex($i);
            if (! is_string($entryName)) {
                continue;
            }

            if (! preg_match('/(^|\/)word\/(document|header[0-9]+|footer[0-9]+)\.xml$/i', $entryName)) {
                continue;
            }

            $xml = $zip->getFromName($entryName);
            if (! is_string($xml)) {
                continue;
            }

            $updatedXml = $this->replacePlaceholdersInXml($xml, $variables);
            $zip->addFromString($entryName, $updatedXml);
        }

        $zip->close();

        $buffer = file_get_contents($tempFile);
        @unlink($tempFile);

        if (! is_string($buffer)) {
            throw new RuntimeException('Unable to read generated DOCX file.');
        }

        return [
            'buffer' => $buffer,
            'filename' => $downloadName,
        ];
    }

    private function convertDocxBufferToPdf(string $docxBuffer): string
    {
        @set_time_limit(0);

        $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aslaw-lod-' . uniqid('', true);
        if (! mkdir($tempDir) && ! is_dir($tempDir)) {
            throw new RuntimeException('Unable to create temporary conversion directory.');
        }

        $inputDocxPath = $tempDir . DIRECTORY_SEPARATOR . 'lod-template.docx';
        $outputPdfPath = $tempDir . DIRECTORY_SEPARATOR . 'lod-template.pdf';
        file_put_contents($inputDocxPath, $docxBuffer);

        $candidates = array_values(array_filter([
            env('LIBREOFFICE_PATH'),
            'C:\\Program Files\\LibreOffice\\program\\soffice.exe',
            'C:\\Program Files (x86)\\LibreOffice\\program\\soffice.exe',
            '/usr/bin/soffice',
            '/usr/local/bin/soffice',
            'soffice',
        ]));

        $lastError = null;

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        foreach ($candidates as $command) {
            try {
                // Use array form with bypass_shell so spaces in the path are handled
                // correctly on Windows without going through cmd.exe.
                $cmdArr = [
                    (string) $command,
                    '--headless',
                    '--convert-to', 'pdf',
                    '--outdir', $tempDir,
                    $inputDocxPath,
                ];

                $pipes = [];
                $process = proc_open($cmdArr, $descriptors, $pipes, $tempDir, null, ['bypass_shell' => true]);

                if (! is_resource($process)) {
                    $lastError = "proc_open failed for command: {$command}";
                    continue;
                }

                fclose($pipes[0]);
                $stdout = stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $exitCode = proc_close($process);

                if ($exitCode !== 0) {
                    $lastError = trim((string) $stdout . PHP_EOL . (string) $stderr);
                    continue;
                }

                if (! file_exists($outputPdfPath)) {
                    $lastError = 'Conversion command completed but PDF was not found.';
                    continue;
                }

                $pdfBuffer = file_get_contents($outputPdfPath);
                $this->deleteDir($tempDir);

                if (! is_string($pdfBuffer)) {
                    throw new RuntimeException('Unable to read converted PDF.');
                }

                return $pdfBuffer;
            } catch (\Throwable $error) {
                $lastError = $error->getMessage();
            }
        }

        $this->deleteDir($tempDir);

        throw new RuntimeException('Unable to convert DOCX to PDF. Install LibreOffice and ensure soffice is available. ' . (string) $lastError);
    }

    private function syncWorkbook(string $folder, array $candidates, string $outputName, array $columnOrder, array $variables): string
    {
        $templatePath = $this->resolveTemplatePath($folder, $candidates);
        $tempFile = tempnam(sys_get_temp_dir(), 'aslaw-xlsx-');

        if (! $tempFile) {
            throw new RuntimeException('Unable to allocate temporary workbook file.');
        }

        copy($templatePath, $tempFile);

        $zip = new ZipArchive();
        if ($zip->open($tempFile) !== true) {
            @unlink($tempFile);
            throw new RuntimeException('Unable to open XLSX template archive.');
        }

        $sheetPath = 'xl/worksheets/sheet1.xml';
        $sheetXml = $zip->getFromName($sheetPath);
        if (! is_string($sheetXml)) {
            $zip->close();
            @unlink($tempFile);
            throw new RuntimeException('sheet1.xml not found in workbook template.');
        }

        $cells = [];
        foreach ($columnOrder as $index => $columnName) {
            $cellRef = $this->columnIndexToLetters($index + 1) . '2';
            $value = $this->escapeXml((string) ($variables[$columnName] ?? ''));
            $cells[] = '<c r="' . $cellRef . '" t="inlineStr"><is><t xml:space="preserve">' . $value . '</t></is></c>';
        }

        $rowXml = '<row r="2">' . implode('', $cells) . '</row>';

        if (preg_match('~<row r="2"[^>]*>[\s\S]*?</row>~', $sheetXml)) {
            $sheetXml = preg_replace('~<row r="2"[^>]*>[\s\S]*?</row>~', $rowXml, $sheetXml, 1) ?? $sheetXml;
        } else {
            $sheetXml = str_replace('</sheetData>', $rowXml . '</sheetData>', $sheetXml);
        }

        $zip->addFromString($sheetPath, $sheetXml);
        $zip->close();

        $destinationDir = $this->templateRoot . DIRECTORY_SEPARATOR . $folder;
        if (! is_dir($destinationDir)) {
            mkdir($destinationDir, 0777, true);
        }

        $destinationPath = $destinationDir . DIRECTORY_SEPARATOR . $outputName;
        copy($tempFile, $destinationPath);
        @unlink($tempFile);

        return $destinationPath;
    }

    private function resolveTemplatePath(string $folder, array $candidates): string
    {
        $baseDir = $this->templateRoot . DIRECTORY_SEPARATOR . $folder;

        foreach ($candidates as $candidate) {
            $path = $baseDir . DIRECTORY_SEPARATOR . $candidate;
            if (is_file($path)) {
                return $path;
            }
        }

        throw new RuntimeException('Template file not found in ' . $baseDir . '. Tried: ' . implode(', ', $candidates));
    }

    private function replacePlaceholdersInXml(string $xml, array $variables): string
    {
        return preg_replace_callback('/\{\{([\s\S]*?)\}\}/', function (array $matches) use ($variables) {
            $rawKey = (string) ($matches[1] ?? '');
            $normalizedKey = preg_replace('/<[^>]+>/', '', $rawKey) ?? $rawKey;
            $normalizedKey = html_entity_decode($normalizedKey, ENT_QUOTES | ENT_XML1, 'UTF-8');
            $key = trim($normalizedKey);

            if ($key === '') {
                return '';
            }

            if (! array_key_exists($key, $variables)) {
                return '';
            }

            $value = $variables[$key];

            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }

            return $this->escapeXml((string) $value);
        }, $xml) ?? $xml;
    }

    private function buildLodVariables(array $formData): array
    {
        $defaults = [
                'Date' => date('Y-m-d'),
            'YourCompanyName' => '',
            'YourCompanyAddressLine1' => '',
            'YourCompanyAddressLine2' => '',
            'YourCompanyPhone' => '',
            'YourCompanyEmail' => '',
            'RecipientName' => '',
            'RecipientCompanyName' => '',
            'RecipientAddressLine1' => '',
            'RecipientAddressLine2' => '',
            'RecipientSalutation' => 'Sir/Madam',
            'Reference' => '',
            'Currency' => 'RM',
            'AmountDue' => '',
            'GoodsOrServices' => '',
            'AgreementType' => '',
            'AgreementDate' => '',
            'InvoiceNumber' => '',
            'DueDate' => '',
            'ReminderDates' => '',
            'PaymentWindowDays' => '7',
            'FinalPaymentDate' => '',
            'PaymentInstructions' => '',
            'RemittanceEmail' => '',
            'ContactPerson' => '',
            'ContactPhone' => '',
            'ContactEmail' => '',
            'DisputeWindowDays' => '3',
            'YourSignerName' => '',
            'YourSignerTitle' => '',
            'ClientName' => '',
            'ClientServiceAddress' => '',
            'BackgroundFacts' => '',
            'DefamationActs' => '',
            'DefamatoryStatementsDetails' => '',
            'ImageUploadDetails' => '',
            'AdditionalPublicationDetails' => '',
            'ReshareDetails' => '',
            'CaseDescription' => '',
            'Signed' => '',
            'SignedImageDataUrl' => '',
            'LegalClient' => '',
            'MainSocialAccount' => '',
            'DeliveryByRegisteredPost' => true,
            'DeliveryByHand' => false,
            'DeliveryByOrdinaryPost' => false,
            'DeliveryByWhatsAppEmail' => true,
            'DeliveryByCourier' => false,
            'DeliveryByARRegisteredPost' => false,
        ];

        $variables = $defaults;
        foreach ($defaults as $key => $defaultValue) {
            if (array_key_exists($key, $formData)) {
                $variables[$key] = $formData[$key];
                continue;
            }

            $placeholderKey = '{{' . $key . '}}';
            if (array_key_exists($placeholderKey, $formData)) {
                $variables[$key] = $formData[$placeholderKey];
            }
        }

        $defamationKeys = [
            'BackgroundFacts',
            'DefamationActs',
            'DefamatoryStatementsDetails',
            'ImageUploadDetails',
            'AdditionalPublicationDetails',
            'ReshareDetails',
        ];

        $defamationValues = [];
        foreach ($defamationKeys as $key) {
            $rawValue = $variables[$key] ?? '';
            $normalized = trim((string) $rawValue);
            if ($normalized !== '') {
                $defamationValues[] = $normalized;
            }
        }

        foreach ($defamationKeys as $index => $key) {
            if (array_key_exists($index, $defamationValues)) {
                $variables[$key] = ($index + 1) . '. ' . $defamationValues[$index];
                continue;
            }

            $variables[$key] = '';
        }

        $checkboxAliases = [
            'By Registered Post' => ['source' => 'DeliveryByRegisteredPost', 'label' => 'By Registered Post'],
            'By Hand' => ['source' => 'DeliveryByHand', 'label' => 'By Hand'],
            'By Ordinary Post' => ['source' => 'DeliveryByOrdinaryPost', 'label' => 'By Ordinary Post'],
            'By Whatsapp / Email' => ['source' => 'DeliveryByWhatsAppEmail', 'label' => 'By Whatsapp / Email'],
            'By Courier' => ['source' => 'DeliveryByCourier', 'label' => 'By Courier'],
            'By A.R. Registered Post' => ['source' => 'DeliveryByARRegisteredPost', 'label' => 'By A.R. Registered Post'],
        ];

        foreach ($checkboxAliases as $placeholderLabel => $meta) {
            $sourceKey = (string) ($meta['source'] ?? '');
            $label = (string) ($meta['label'] ?? $placeholderLabel);
            $isChecked = filter_var($variables[$sourceKey] ?? false, FILTER_VALIDATE_BOOL);
            $variables[$placeholderLabel] = sprintf('[%s] %s', $isChecked ? 'x' : ' ', $label);
        }

        if (trim((string) $variables['CaseDescription']) === '') {
            $variables['CaseDescription'] = trim((string) ($variables['BackgroundFacts'] ?? ''));
        }

        if (is_string($variables['Signed'] ?? null) && preg_match('~^data:image/~i', (string) $variables['Signed'])) {
            // Avoid leaking raw data URLs into generated DOCX/XML text.
            $variables['Signed'] = '';
        }

        if (trim((string) ($variables['Signed'] ?? '')) === '' && trim((string) ($variables['SignedImageDataUrl'] ?? '')) !== '') {
            $variables['Signed'] = '[Signed Image]';
        }

        return $variables;
    }

    private function buildWritVariables(array $formData): array
    {
        $defaults = [
            'WritCourtHeading1' => 'DALAM MAHKAMAH MAJISTRET DI SHAH ALAM',
            'WritCourtHeading2' => 'DALAM NEGERI SELANGOR DARUL EHSAN, MALAYSIA',
            'WritCaseNoLabel' => 'GUAMAN NO:',
            'WritCaseNumber' => '',
            'WritCaseYear' => (string) date('Y'),
            'CaseNoReference' => '',
            'CaseYear' => (string) date('Y'),
            'PlaintiffName' => '',
            'PlaintiffNRIC' => '',
            'Defendant1Name' => '',
            'Defendant1NRIC' => '',
            'Defendant2Name' => '',
            'Defendant2NRIC' => '',
            'Defendant1Address' => '',
            'Defendant2Address' => '',
            'Place' => '',
            'CourtPlace2' => '',
            'AppearanceDays' => '14',
            'AppearanceDaysWord' => 'empat belas',
            'RegistrarCourt' => 'Mahkamah Majistret Shah Alam',
            'WitnessDay' => '',
            'WitnessMonth' => '',
            'WitnessYear' => (string) date('Y'),
            'PlaintiffSolicitor' => '',
            'LawyerName' => '',
            'OpponentLawyer' => '',
            'PlaintiffFirmName' => 'Tetuan Adnan Sharida & Associates',
            'PlaintiffFirmAddress' => '',
            'FirmAddress' => '',
            'DamagesAmount' => '40,200.00',
            'SDamagesText' => 'Gantirugi Khas',
            'GeneralDamagesAmount' => '40,200.00',
            'SpecialDamagesText' => 'Gantirugi Khas',
            'InterestRate' => '5',
            'InterestFromText' => 'dari tarikh penghakiman sehingga penyelesaian penuh',
            'CostsActionText' => 'Kos tindakan',
            'OtherReliefText' => 'Apa-apa relif yang difikirkan sesuai dan adil oleh mahkamah',
            'InitialCostsAmount' => '225.00',
            'SubstitutedServiceCostsAmount' => '60.00',
            'PostagePrice' => '',
            'ServiceOfficer' => '',
            'ServiceMethod' => '',
            'ServiceKnownBy' => '',
            'ServiceAt' => '',
            'ServiceOnDate' => '',
            'EndorsementDate' => '',
            'ServerName' => '',
            'FilingFirmAddress' => '',
            'FilingFirmTel' => '',
            'FilingFirmEmail' => '',
            'FilingReference' => '',
            'Signed' => '',
            'SignedImageDataUrl' => '',

            // Legacy compatibility keys
            'Date' => date('j M Y'),
            'CourtName' => 'Mahkamah Majistret',
            'CourtLocation' => 'Shah Alam',
            'StateName' => 'Selangor Darul Ehsan, Malaysia',
            'CaseNumber' => '',
            'CaseYear' => (string) date('Y'),
            'DefendantName' => '',
            'DefendantNRIC' => '',
            'DefendantAddressLine1' => '',
            'DefendantAddressLine2' => '',
            'Defendant2AddressLine1' => '',
            'Defendant2AddressLine2' => '',
            'ClaimAmount' => '40200.00',
            'Currency' => 'RM',
            'ClaimDescription' => 'Pernyataan Tuntutan',
            'LawyerName' => '',
            'LawFirmName' => 'Tetuan Adnan Sharida & Associates',
            'LawFirmAddress' => '',
            'LawyerPhone' => '',
            'LawyerEmail' => '',
            'CourtSealReference' => '',
            'ServiceServerName' => '',
            'ServiceLocation' => '',
            'ServiceDate' => '',
            'WitnessedCourt' => 'Mahkamah Majistret Shah Alam',
            'ReferenceCode' => '',
        ];

        $variables = $defaults;
        foreach ($defaults as $key => $defaultValue) {
            if (array_key_exists($key, $formData)) {
                $variables[$key] = (string) $formData[$key];
                continue;
            }

            $placeholderKey = '{{' . $key . '}}';
            if (array_key_exists($placeholderKey, $formData)) {
                $variables[$key] = (string) $formData[$placeholderKey];
            }
        }

        // New -> legacy mapping
        $variables['CaseNumber'] = $variables['CaseNumber'] ?: $variables['WritCaseNumber'];
        $variables['CaseYear'] = $variables['CaseYear'] ?: $variables['WritCaseYear'];
        $variables['CaseNoReference'] = $variables['CaseNoReference'] ?: $variables['WritCaseNumber'];
        $variables['WritCaseNumber'] = $variables['WritCaseNumber'] ?: $variables['CaseNoReference'];
        $variables['WritCaseYear'] = $variables['WritCaseYear'] ?: $variables['CaseYear'];
        $variables['DefendantName'] = $variables['DefendantName'] ?: $variables['Defendant1Name'];
        $variables['DefendantNRIC'] = $variables['DefendantNRIC'] ?: $variables['Defendant1NRIC'];
        if ($variables['DefendantAddressLine1'] === '' && $variables['Defendant1Address'] !== '') {
            $variables['DefendantAddressLine1'] = $variables['Defendant1Address'];
        }
        if ($variables['Defendant2AddressLine1'] === '' && $variables['Defendant2Address'] !== '') {
            $variables['Defendant2AddressLine1'] = $variables['Defendant2Address'];
        }
        $variables['LawFirmName'] = $variables['LawFirmName'] ?: $variables['PlaintiffFirmName'];
        $variables['LawFirmAddress'] = $variables['LawFirmAddress'] ?: ($variables['PlaintiffFirmAddress'] ?: $variables['FilingFirmAddress']);
        $variables['FirmAddress'] = $variables['FirmAddress'] ?: ($variables['FilingFirmAddress'] ?: $variables['PlaintiffFirmAddress']);
        $variables['PlaintiffFirmAddress'] = $variables['PlaintiffFirmAddress'] ?: ($variables['FirmAddress'] ?: $variables['FilingFirmAddress']);
        $variables['LawyerName'] = $variables['LawyerName'] ?: $variables['PlaintiffSolicitor'];
        $variables['PlaintiffSolicitor'] = $variables['PlaintiffSolicitor'] ?: $variables['LawyerName'];
        $variables['DamagesAmount'] = $variables['DamagesAmount'] ?: ($variables['GeneralDamagesAmount'] ?: $variables['ClaimAmount']);
        $variables['SDamagesText'] = $variables['SDamagesText'] ?: $variables['SpecialDamagesText'];
        $variables['GeneralDamagesAmount'] = $variables['GeneralDamagesAmount'] ?: $variables['DamagesAmount'];
        $variables['SpecialDamagesText'] = $variables['SpecialDamagesText'] ?: $variables['SDamagesText'];
        $variables['LawyerPhone'] = $variables['LawyerPhone'] ?: $variables['FilingFirmTel'];
        $variables['LawyerEmail'] = $variables['LawyerEmail'] ?: $variables['FilingFirmEmail'];
        $variables['ReferenceCode'] = $variables['ReferenceCode'] ?: $variables['FilingReference'];
        $variables['ServiceServerName'] = $variables['ServiceServerName'] ?: $variables['ServiceOfficer'];
        $variables['ServiceLocation'] = $variables['ServiceLocation'] ?: $variables['ServiceAt'];
        $variables['ServiceDate'] = $variables['ServiceDate'] ?: $variables['ServiceOnDate'];
        $variables['WitnessedCourt'] = $variables['WitnessedCourt'] ?: $variables['RegistrarCourt'];
        $variables['Place'] = $variables['Place'] ?: $variables['CourtLocation'];
        $variables['CourtPlace2'] = $variables['CourtPlace2'] ?: $variables['Place'];

        if (trim((string) $variables['AppearanceDaysWord']) === '') {
            $appearanceValue = (int) preg_replace('/\D+/', '', (string) $variables['AppearanceDays']);
            $appearanceWordMap = [
                1 => 'satu', 2 => 'dua', 3 => 'tiga', 4 => 'empat', 5 => 'lima', 6 => 'enam',
                7 => 'tujuh', 8 => 'lapan', 9 => 'sembilan', 10 => 'sepuluh', 11 => 'sebelas',
                12 => 'dua belas', 13 => 'tiga belas', 14 => 'empat belas', 15 => 'lima belas',
                16 => 'enam belas', 17 => 'tujuh belas', 18 => 'lapan belas', 19 => 'sembilan belas',
                20 => 'dua puluh',
            ];
            $variables['AppearanceDaysWord'] = $appearanceWordMap[$appearanceValue] ?? (string) $variables['AppearanceDays'];
        }

        if (trim((string) $variables['Signed']) === '' && trim((string) $variables['SignedImageDataUrl']) !== '') {
            $variables['Signed'] = '[SIGNED_IMAGE]';
        }

        return $variables;
    }

    private function buildInvoiceVariables(array $formData): array
    {
        $defaults = [
            'id' => '',
            'invoice_id' => '',
            'lawyerID' => '',
            'case_id' => '',
            'clientID' => '',
            'invoice_number' => '',
            'payment_stage' => '',
            'type_of_work' => '',
            'issue_date' => date('j M Y'),
            'due_date' => '',
            'expected_amount' => '',
            'paid_amount' => '',
            'balance' => '',
            'phase_balance' => '',
            'tax' => '',
            'discount' => '',
            'total_amount' => '',
            'client_name' => '',
            'case_title' => '',
            'blob_path' => '',
            'created_at' => '',
            'updated_at' => '',
            'language' => 'english',
        ];

        $aliases = [
            'id' => ['id', 'invoice_id', 'invoiceId'],
            'invoice_id' => ['invoice_id', 'invoiceId', 'id'],
            'lawyerID' => ['lawyerID', 'lawyerId'],
            'case_id' => ['case_id', 'caseId'],
            'clientID' => ['clientID', 'clientId'],
            'invoice_number' => ['invoice_number', 'invoiceNumber'],
            'payment_stage' => ['payment_stage', 'paymentStage'],
            'type_of_work' => ['type_of_work', 'typeOfWork'],
            'issue_date' => ['issue_date', 'issueDate'],
            'due_date' => ['due_date', 'dueDate'],
            'expected_amount' => ['expected_amount', 'expectedAmount'],
            'paid_amount' => ['paid_amount', 'paidAmount'],
            'balance' => ['balance'],
            'phase_balance' => ['phase_balance', 'phaseBalance'],
            'tax' => ['tax'],
            'discount' => ['discount'],
            'total_amount' => ['total_amount', 'totalAmount'],
            'client_name' => ['client_name', 'clientName'],
            'case_title' => ['case_title', 'caseTitle'],
            'blob_path' => ['blob_path', 'blobPath', 'pdf_path', 'pdfPath'],
            'created_at' => ['created_at', 'createdAt'],
            'updated_at' => ['updated_at', 'updatedAt'],
            'language' => ['language'],
        ];

        $variables = [];

        foreach ($defaults as $key => $defaultValue) {
            $resolvedValue = null;
            foreach (($aliases[$key] ?? [$key]) as $candidate) {
                if (array_key_exists($candidate, $formData)) {
                    $resolvedValue = $formData[$candidate];
                    break;
                }
            }

            $variables[$key] = $resolvedValue ?? $defaultValue;
        }

        return $variables;
    }

    private function escapeXml(string $value): string
    {
        return str_replace(
            ['&', '<', '>', '"', "'"],
            ['&amp;', '&lt;', '&gt;', '&quot;', '&apos;'],
            $value
        );
    }

    private function columnIndexToLetters(int $index): string
    {
        $letters = '';

        while ($index > 0) {
            $remainder = ($index - 1) % 26;
            $letters = chr(65 + $remainder) . $letters;
            $index = intdiv($index - 1, 26);
        }

        return $letters;
    }

    private function deleteDir(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if (! is_array($items)) {
            @rmdir($path);
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $itemPath = $path . DIRECTORY_SEPARATOR . $item;
            if (is_dir($itemPath)) {
                $this->deleteDir($itemPath);
            } else {
                @unlink($itemPath);
            }
        }

        @rmdir($path);
    }
}

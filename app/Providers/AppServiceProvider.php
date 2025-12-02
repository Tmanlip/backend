<?php

    namespace App\Providers;

    use Illuminate\Support\ServiceProvider;
    use Illuminate\Support\Facades\Route;
    use MicrosoftAzure\Storage\Blob\BlobRestProxy;
    use Illuminate\Support\Facades\Storage;
    use League\Flysystem\Filesystem;
    use League\Flysystem\AzureBlobStorage\AzureBlobStorageAdapter;


    class AppServiceProvider extends ServiceProvider
    {
        /**
         * Register any application services.
         */
        public function register(): void
        {
            //
        }

        /**
         * Bootstrap any application services.
         */
        public function boot(): void
        {
            // Load the routes here directly
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));
            Route::middleware('web')
                ->group(base_path('routes/web.php'));

            Storage::extend('azure', function ($app, $config) {

                $client = BlobRestProxy::createBlobService(
                    $config['connection_string']
                );

                $adapter = new AzureBlobStorageAdapter(
                    $client,
                    $config['container']
                );

                // Create Flysystem instance
                $flysystem = new \League\Flysystem\Filesystem($adapter);

                // Return Laravel FilesystemAdapter (correct v10+ constructor)
                return new \Illuminate\Filesystem\FilesystemAdapter(
                    $flysystem,
                    $adapter,
                    $config
                );
            });

        }
        
    }



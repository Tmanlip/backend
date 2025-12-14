<!DOCTYPE html>
<html>
<head>
    <title>Azure Blob Upload</title>
</head>
<body>

<h2>Upload a File to Azure Blob Storage</h2>

<form action="/upload" method="POST" enctype="multipart/form-data">
    @csrf
    <input type="file" name="file" required>
    <button type="submit">Upload</button>
</form>

</body>
</html>

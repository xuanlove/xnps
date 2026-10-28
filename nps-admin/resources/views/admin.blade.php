<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>XNPS 管理控制台</title>
    @vite(['resources/css/admin.css', 'resources/js/admin/main.js'])
</head>
<body>
<div id="admin-app"></div>
</body>
</html>

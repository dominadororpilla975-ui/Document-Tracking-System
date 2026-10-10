<?php
if (!defined('APP_ROUTE_ENTRY')) {
    http_response_code(404);
    exit('Not Found');
}

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This backup command must be run from the server console.');
}

require_once APP_ROOT . '/config/db.php';

$backupRoot = APP_ROOT . DIRECTORY_SEPARATOR . 'backups';
if (!is_dir($backupRoot) && !mkdir($backupRoot, 0700, true)) {
    throw new RuntimeException('Unable to create backup directory.');
}

$stamp = date('Y-m-d_H-i-s');
$sqlFile = $backupRoot . DIRECTORY_SEPARATOR . DB_NAME . '_' . $stamp . '.sql';
$dumpBinary = getenv('MYSQLDUMP_PATH') ?: 'mysqldump';
$command = escapeshellarg($dumpBinary)
    . ' --host=' . escapeshellarg(DB_HOST)
    . ' --port=' . escapeshellarg(DB_PORT)
    . ' --user=' . escapeshellarg(DB_USER)
    . ' --password=' . escapeshellarg(DB_PASS)
    . ' --single-transaction --routines --triggers '
    . escapeshellarg(DB_NAME) . ' > ' . escapeshellarg($sqlFile);

$output = [];
$exitCode = 0;
exec($command . ' 2>&1', $output, $exitCode);
if ($exitCode !== 0 || !is_file($sqlFile) || filesize($sqlFile) === 0) {
    @unlink($sqlFile);
    throw new RuntimeException('Database backup failed: ' . implode("\n", $output));
}

$uploadsPath = APP_ROOT . DIRECTORY_SEPARATOR . 'uploads';
if (class_exists('ZipArchive')) {
    $uploadsArchive = $backupRoot . DIRECTORY_SEPARATOR . 'uploads_' . $stamp . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($uploadsArchive, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Unable to create uploaded-files backup archive.');
    }
    if (is_dir($uploadsPath)) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploadsPath, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $zip->addFile($file->getPathname(), substr($file->getPathname(), strlen($uploadsPath) + 1));
            }
        }
    }
    $zip->close();
    echo "Created:\n$sqlFile\n$uploadsArchive\n";
    exit(0);
}

// Keep backups usable on installations without the PHP ZIP extension.
$uploadsBackup = $backupRoot . DIRECTORY_SEPARATOR . 'uploads_' . $stamp;
if (is_dir($uploadsPath) && !copyDirectory($uploadsPath, $uploadsBackup)) {
    throw new RuntimeException('Unable to copy uploaded files to the backup directory.');
}

echo "Created:\n$sqlFile\n$uploadsBackup\n";

function copyDirectory(string $source, string $destination): bool
{
    if (!is_dir($destination) && !mkdir($destination, 0700, true)) {
        return false;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        $relativePath = substr($item->getPathname(), strlen($source) + 1);
        $target = $destination . DIRECTORY_SEPARATOR . $relativePath;
        if ($item->isDir()) {
            if (!is_dir($target) && !mkdir($target, 0700, true)) {
                return false;
            }
        } elseif (!copy($item->getPathname(), $target)) {
            return false;
        }
    }

    return true;
}

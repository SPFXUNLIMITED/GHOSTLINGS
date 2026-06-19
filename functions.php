<?php
function asset($file) {
    $filepath = __DIR__ . '/' . $file;
    if (file_exists($filepath)) {
        $version = filemtime($filepath);
        return '/' . $file . '?v=' . $version;
    }
    return '/' . $file;
}

<?php
$modules_dir = 'c:/Projects/musesys-mega/modules';
$files = array();
if (is_dir($modules_dir)) {
    foreach (glob($modules_dir . '/*/index.php') as $file) {
        $files[] = str_replace($modules_dir . '/', '', $file);
    }
    foreach (glob($modules_dir . '/*/*.php') as $file) {
        if (basename($file) !== 'index.php') {
            $files[] = str_replace($modules_dir . '/', '', $file);
        }
    }
}
echo "    \$modules = [\n";
foreach ($files as $f) {
    echo "        '" . $f . "',\n";
}
echo "    ];\n";

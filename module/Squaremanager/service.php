public function deployAdapterFiles()
{
    $adapterSource = __DIR__ . '/installer_files/';
    $adapterTarget = PATH_LIBRARY . '/Payment/Adapter/';

    $publicSource = __DIR__ . '/installer_files/public/assets/gateways/';
    $publicTarget = PATH_ROOT . '/public/assets/gateways/';

    $adapterFiles = ['Square.php', 'square-checkout.js'];
    $logoFiles = ['square.png'];

    // Copy adapter files
    foreach ($adapterFiles as $file) {
        @copy($adapterSource . $file, $adapterTarget . $file);
    }

    // Ensure gateways folder exists
    if (!is_dir($publicTarget)) {
        mkdir($publicTarget, 0755, true);
    }

    // Copy logo file
    foreach ($logoFiles as $file) {
        @copy($publicSource . $file, $publicTarget . $file);
    }
}
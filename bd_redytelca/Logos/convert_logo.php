<?php
// Convierte logo.webp a PNG y WebP con fondo transparente eliminando píxeles cercanos al blanco.
$src = __DIR__ . '/logo.webp';
$out = __DIR__ . '/logo_transparent.png';
$outWebP = __DIR__ . '/logo_transparent.webp';
if (!file_exists($src)) {
    echo "Archivo fuente no encontrado: $src\n";
    exit(1);
}

// Intentar cargar WebP: primero GD, luego Imagick
if (function_exists('imagecreatefromwebp')) {
    $img = imagecreatefromwebp($src);
    if (!$img) {
        echo "No se pudo cargar la imagen WebP con GD.\n";
        exit(1);
    }
    $w = imagesx($img);
    $h = imagesy($img);
    $outImg = imagecreatetruecolor($w, $h);
    imagesavealpha($outImg, true);
    $trans_colour = imagecolorallocatealpha($outImg, 0, 0, 0, 127);
    imagefill($outImg, 0, 0, $trans_colour);
    $threshold = 240; // cerca de blanco (0-255)
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $rgb = imagecolorat($img, $x, $y);
            $r = ($rgb >> 16) & 0xFF;
            $g = ($rgb >> 8) & 0xFF;
            $b = $rgb & 0xFF;
            if ($r >= $threshold && $g >= $threshold && $b >= $threshold) {
                continue;
            }
            $color = imagecolorallocatealpha($outImg, $r, $g, $b, 0);
            imagesetpixel($outImg, $x, $y, $color);
        }
    }
    $generatedWebP = false;
    if (imagepng($outImg, $out)) {
        if (function_exists('imagewebp')) {
            imagesavealpha($outImg, true);
            if (imagewebp($outImg, $outWebP, 90)) {
                $generatedWebP = true;
            }
        }
        echo "Generado: $out" . ($generatedWebP ? " y $outWebP" : "") . "\n";
        imagedestroy($img);
        imagedestroy($outImg);
        exit(0);
    }
    echo "Fallo al guardar PNG desde GD.\n";
    exit(1);
} elseif (class_exists('Imagick')) {
    try {
        $i = new Imagick($src);
        $i->setImageFormat('png32');
        // Hacer blanco transparente usando fuziness
        $i->transparentPaintImage('#ffffff', 0, 10, false);
        $i->writeImage($out);
        $webpSaved = false;
        try {
            $i->setImageFormat('webp');
            $i->writeImage($outWebP);
            $webpSaved = true;
        } catch (Exception $e) {
            // Ignorar si no es posible generar WebP aquí.
        }
        echo "Generado con Imagick: $out" . ($webpSaved ? " y $outWebP" : "") . "\n";
        $i->clear();
        $i->destroy();
        exit(0);
    } catch (Exception $e) {
        echo "Imagick error: " . $e->getMessage() . "\n";
        exit(1);
    }
} else {
    echo "Ni GD con WebP ni Imagick están disponibles en PHP.\n";
    exit(1);
}

?>

<?php
/**
 * Manifest do PWA gerado em tempo real: o app roda tanto em
 * publishdev.com.br/walkietalkie quanto na raiz de voip.usegrupodona.com.br,
 * e o manifest precisa refletir a base/identidade certa em cada um.
 */
$config = require __DIR__ . '/../config/bootstrap.php';

$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
if ($base === '/') { $base = ''; }

$host = $_SERVER['HTTP_HOST'] ?? '';
$isDona = stripos($host, 'voip.') === 0;

$manifest = [
    'id'          => $base . '/',
    'name'        => $isDona ? 'Voip Dona' : ($config['app']['name'] ?? 'Walkie Talkie'),
    'short_name'  => $isDona ? 'Voip Dona' : 'WalkieTalkie',
    'description' => $isDona
        ? 'Rádio da equipe Donattela — aperte e fale.'
        : 'Comunicação em tempo real estilo rádio comunicador profissional.',
    'version'     => $config['app']['version'] ?? '1.0.0',
    'start_url'   => $base . '/',
    'scope'       => $base . '/',
    'display'     => 'standalone',
    'orientation' => 'portrait',
    'background_color' => $isDona ? '#FAF7F4' : '#0a0a0a',
    'theme_color'      => $isDona ? '#FAF7F4' : '#0a0a0a',
    'lang'        => 'pt-BR',
    'categories'  => ['communication', 'productivity'],
    'icons'       => [
        [
            'src'     => $base . '/icons/icon-192.png',
            'sizes'   => '192x192',
            'type'    => 'image/png',
            'purpose' => 'any maskable',
        ],
        [
            'src'     => $base . '/icons/icon-512.png',
            'sizes'   => '512x512',
            'type'    => 'image/png',
            'purpose' => 'any maskable',
        ],
    ],
];

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: no-cache');
echo json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

<?php
/** Página /mftsports/ (requiere una página de WordPress con el slug "mftsports"). */
get_template_part('template-parts/catalog-page', null, [
    'line' => 'sport',
    'slides' => 'mftsports',
    'title' => 'MFTSports',
    'empty' => 'No hay eventos deportivos disponibles en este momento.',
    'sold_out_label' => 'Sold Out',
]);

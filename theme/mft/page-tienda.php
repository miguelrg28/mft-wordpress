<?php
/** Página /tienda/ (requiere una página de WordPress con el slug "tienda"). */
get_template_part('template-parts/catalog-page', null, [
    'line' => 'shop',
    'slides' => 'tienda',
    'title' => 'Tienda',
    'empty' => 'No hay productos disponibles en este momento.',
    'sold_out_label' => 'Agotado',
]);

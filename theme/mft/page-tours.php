<?php
/** Página /tours/ (requiere una página de WordPress con el slug "tours"). */
get_template_part('template-parts/catalog-page', null, [
    'line' => 'tour',
    'slides' => 'tours',
    'title' => 'Tours Exclusivos',
    'empty' => 'No hay tours disponibles en este momento.',
    'sold_out_label' => 'Sold Out',
]);

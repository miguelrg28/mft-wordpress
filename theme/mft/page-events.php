<?php
/** Página /events/ (requiere una página de WordPress con el slug "events"). */
get_template_part('template-parts/catalog-page', null, [
    'line' => 'event',
    'slides' => 'events',
    'title' => 'Eventos y Conciertos',
    'empty' => 'No hay eventos disponibles en este momento.',
    'sold_out_label' => 'Sold Out',
]);

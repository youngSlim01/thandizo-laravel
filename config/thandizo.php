<?php

/*
| Cliente desta instalação do THANDIZO. O produto chama-se sempre THANDIZO;
| a organização e o programa aparecem junto do nome, nos ecrãs, no recibo,
| no cartão e nos PDFs. Vazios, só aparece THANDIZO.
*/

return [

    'client' => [
        // Organização cliente, ex.: "Save the Children".
        'name' => env('THANDIZO_CLIENT_NAME'),

        // Programa em que o THANDIZO é usado, ex.: "Projecto LINK GCF".
        'programme' => env('THANDIZO_PROGRAMME_NAME'),

        // Frase da organização mostrada na página de entrada (opcional).
        'tagline' => env('THANDIZO_CLIENT_TAGLINE'),
    ],

];

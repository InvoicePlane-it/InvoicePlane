<?php

defined('BASEPATH') || exit('No direct script access allowed');

/*
 * Install: composer install (on application/libraries/XMLtemplates/fatturapa/)
 *
 * FatturaPA v1.2
 * https://www.fatturapa.gov.it/it/norme-e-regole/documentazione-fattura-elettronica/formato-fatturapa/index.html
 * https://github.com/s2software/fatturapa (See in libraries/XMLtemplates/fatturapa folder)
 * https://github.com/s2software/fatturapa/wiki/Costanti#formato-trasmissione
 * 
 */
$xml_setting = [
    'full-name' => 'FatturaPA v1.2',
    'countrycode' => 'IT',
    // Nome della classe template da usare (senza 'Xml')
    'generator' => 'Fatturapav12',
    // Nome del file XML embedded (per Factur-X/ZUGFeRD)
    'embedXML' => 'factur-x.xml',
    // Nome dell'XML generato
    'XMLname' => '',
    // Usa legacy calculation? (false = item-level taxes)
    'legacy_calculation' => false,
];

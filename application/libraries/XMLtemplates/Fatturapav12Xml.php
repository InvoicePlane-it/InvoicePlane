<?php

defined('BASEPATH') || exit('No direct script access allowed');
/*
 * InvoicePlane
 *
 * @author      InvoicePlane Developers & Contributors
 * @copyright   Copyright (c) 2012 - 2025 InvoicePlane.com
 * @license     https://invoiceplane.com/license.txt
 * @link        https://invoiceplane.com
 *
 * https://www.fatturapa.gov.it/it/norme-e-regole/documentazione-fattura-elettronica/formato-fatturapa/index.html
 * fatturaPA validazione / Validation tools:
 * https://www.amministrazionicomunali.it/fatturexml/
 * https://www.fatturacheck.it/
 *
 * Notes:
 * Need https://github.com/s2software/fatturapa [WIKI](https://github.com/s2software/fatturapa/wiki/Costanti#condizioni-pagamento)
 * For a 1st time, in fatturapa folder make a `composer install` (think `composer upgrade` if library need update)
 *
 */

/**
 * Class Fatturapav12Xml.
 */
#[\AllowDynamicProperties]
class Fatturapav12Xml
{
    public $invoice;

    public $items;

    public $filename;

    public $options;

    public $currencyCode;

    public $legacy_calculation = false;

    /**
     * @var bool
     */
    public $notax;

    /**
     * @var mixed[]
     */
    public $itemsSubtotalGroupedByTaxPercent = [];

    public function __construct(array $params)
    {
        $CI = & get_instance();
        $this->invoice = $params['invoice'];
        $this->items = $params['items'];
        $this->filename = $params['filename'];
        $this->options = $params['options'] ?? [];
        $this->currencyCode = $CI->mdl_settings->setting('currency_code');
        $this->legacy_calculation = config_item('legacy_calculation');
        $this->itemsSubtotalGroupedByTaxPercent = $this->itemsSubtotalGroupedByTaxPercent();
        $this->notax = $this->itemsSubtotalGroupedByTaxPercent === [];
        
        // Carica configurazioni FatturaPA italiane
        $this->loadItalianConfig();
    }

    /**
     * Recupera il valore di un custom field per ID
     * 
     * @param string $type 'user' o 'client' o 'invoice'
     * @param int $field_id ID del custom field
     * @param int|null $record_id ID del record (se null, usa l'invoice corrente)
     * @return string|null
     */
    protected function getCustomFieldValue($type, $field_id, $record_id = null)
    {
        $CI = & get_instance();
        
        if ($type === 'user') {
            // Custom fields utente
            $CI->load->model('custom_fields/mdl_user_custom');
            $user_id = $record_id ?: $this->invoice->user_id;
            $custom = $CI->mdl_user_custom
                ->where('user_id', $user_id)
                ->where('user_custom_fieldid', $field_id)
                ->get()
                ->row();
            
            return $custom ? $custom->user_custom_fieldvalue : null;
            
        } elseif ($type === 'client') {
            // Custom fields cliente
            $CI->load->model('custom_fields/mdl_client_custom');
            $client_id = $record_id ?: $this->invoice->client_id;
            $custom = $CI->mdl_client_custom
                ->where('client_id', $client_id)
                ->where('client_custom_fieldid', $field_id)
                ->get()
                ->row();
            
            return $custom ? $custom->client_custom_fieldvalue : null;
            
        } elseif ($type === 'invoice') {
            // Custom fields fattura
            $CI->load->model('custom_fields/mdl_invoice_custom');
            $invoice_id = $record_id ?: $this->invoice->invoice_id;
            $custom = $CI->mdl_invoice_custom
                ->where('invoice_id', $invoice_id)
                ->where('invoice_custom_fieldid', $field_id)
                ->get()
                ->row();
            
            return $custom ? $custom->invoice_custom_fieldvalue : null;
        }
        
        return null;
    }

    /**
     * Carica le configurazioni custom fields italiane dal environment
     */
    protected function loadItalianConfig()
    {
        // Custom field IDs per Utente (Fornitore)
        $this->IT_UTENTE_REGIMEFISC_ID = env('IT_UTENTE_REGIMEFISC_ID');
        $this->IT_UTENTE_NATURA_IVA0_ID = env('IT_UTENTE_NATURA_IVA0_ID');
        $this->IT_UTENTE_PROGR_XML_ID = env('IT_UTENTE_PROGR_XML_ID');
        
        // Custom field IDs per Cliente
        $this->IT_CLIENTE_FORMATO_XML_ID = env('IT_CLIENTE_FORMATO_XML_ID');
        $this->IT_CLIENTE_SDI_CODICE_ID = env('IT_CLIENTE_SDI_CODICE_ID');
        $this->IT_CLIENTE_SDI_PEC_ID = env('IT_CLIENTE_SDI_PEC_ID');
        
        // Metodi di pagamento
        $this->IT_METODO_PAGAMENTO = [
            1 => env('IT_METODO_PAGAMENTO_ID_1_CODICE') ?: 'MP01',
            2 => env('IT_METODO_PAGAMENTO_ID_2_CODICE') ?: 'MP08',
            3 => env('IT_METODO_PAGAMENTO_ID_3_CODICE') ?: 'MP05',
        ];
    }

    /**
     * Valida un codice fiscale italiano (solo formato)
     * 
     * @param string $cf
     * @return bool
     */
    protected function isValidCodiceFiscale($cf)
    {
        if (empty($cf)) {
            return false;
        }
        
        $cf = strtoupper(trim($cf));
        
        // P.IVA (11 cifre numeriche)
        if (preg_match('/^[0-9]{11}$/', $cf)) {
            return true;
        }
        
        // Codice Fiscale persone fisiche (16 caratteri alfanumerici nel formato corretto)
        if (preg_match('/^[A-Z]{6}[0-9]{2}[A-Z][0-9]{2}[A-Z][0-9]{3}[A-Z]$/', $cf)) {
            return true;
        }
        
        return false;
    }

    public function xml(): string
    {   
        require_once __DIR__ . '/fatturapa/vendor/autoload.php';

        // Formato FatturaPA - leggi dal custom field o usa default
        $formato = $this->getCustomFieldValue('client', $this->IT_CLIENTE_FORMATO_XML_ID);
        $formato = $formato ?: 'FPR12'; // Default: Privati
        
        $fatturapa = new FatturaPA($formato);
        
        // === MITTENTE (Cedente Prestatore) ===
        $mittente = [
            'ragsoc'     => $this->invoice->user_company,
            'indirizzo'  => $this->invoice->user_address_1 . ($this->invoice->user_address_2 ? PHP_EOL . $this->invoice->user_address_2 : ''),
            'cap'        => $this->invoice->user_zip,
            'comune'     => $this->invoice->user_city,
            'prov'       => $this->invoice->user_state ?: $this->invoice->user_city,
            'paese'      => $this->invoice->user_country,
            'piva'       => $this->invoice->user_vat_id,
            'regimefisc' => $this->getCustomFieldValue('user', $this->IT_UTENTE_REGIMEFISC_ID),
        ];
        
        if ($this->invoice->user_tax_code) {
            $mittente['codfisc'] = $this->invoice->user_tax_code;
        }
        
        $fatturapa->set_mittente($mittente);

        // === DESTINATARIO (Cessionario/Committente) ===
        $destinatario = [
            'paese'      => $this->invoice->client_country,
            'indirizzo'  => $this->invoice->client_address_1 . ($this->invoice->client_address_2 ? PHP_EOL . $this->invoice->client_address_2 : ''),
            'cap'        => $this->invoice->client_zip,
            'comune'     => $this->invoice->client_city,
            'prov'       => $this->invoice->client_state ?: 'EE',
        ];

        $piva_cliente = trim($this->invoice->client_vat_id ?? '');
        $cf_cliente   = trim($this->invoice->client_tax_code ?? '');

        if (empty($piva_cliente) && empty($cf_cliente)) {
            throw new Exception('P.IVA o Codice Fiscale del cliente sono obbligatori.');
        }

        // Determina tipo soggetto dalla lunghezza del CF (16 = persona fisica)
        $is_persona_fisica = (!empty($cf_cliente) && strlen($cf_cliente) === 16);
        $nome    = '';
        $cognome = '';

        if ($is_persona_fisica) {
            // Persona fisica: solo CF, no P.IVA
            $destinatario['codfisc'] = strtoupper($cf_cliente);

            $nome    = trim($this->invoice->client_name ?? '');
            $cognome = trim($this->invoice->client_surname ?? '');

            // Prova a splittare client_name se cognome è vuoto
            if (empty($cognome) && !empty($nome)) {
                $parts = explode(' ', $nome, 2);
                if (count($parts) === 2) {
                    $nome    = $parts[0];
                    $cognome = $parts[1];
                } else {
                    throw new Exception('Per persone fisiche servono Nome E Cognome separati. Compila il campo "Cognome" nel cliente.');
                }
            }

            if (empty($nome) || empty($cognome)) {
                throw new Exception('Nome e Cognome obbligatori per persone fisiche (CF 16 caratteri).');
            }

        } else {
            // Persona giuridica: P.IVA obbligatoria
            if (empty($piva_cliente)) {
                throw new Exception('P.IVA obbligatoria per persone giuridiche (cliente: ' . ($this->invoice->client_company ?? '') . ')');
            }

            $denominazione = trim($this->invoice->client_company ?? '');
            if (empty($denominazione)) {
                throw new Exception('Per persone giuridiche è obbligatorio il campo "Azienda/Ente".');
            }

            $destinatario['ragsoc'] = $denominazione;
            $destinatario['piva']   = $piva_cliente;

            // CF aggiuntivo solo se presente e diverso dalla P.IVA
            if (!empty($cf_cliente) && $cf_cliente !== $piva_cliente) {
                $destinatario['codfisc'] = $cf_cliente;
            }
        }

        // PROVINCIA: sigla 2 lettere obbligatoria per IT
        if ($destinatario['paese'] === 'IT') {
            if (empty($destinatario['prov'])) {
                throw new Exception('Provincia obbligatoria per indirizzi italiani.');
            }
            $prov = strtoupper(trim($destinatario['prov']));
            if (!preg_match('/^[A-Z]{2}$/', $prov)) {
                throw new Exception('Provincia non valida: "' . $prov . '". Usa la sigla di 2 lettere (es: TO, MI, RM).');
            }
            $destinatario['prov'] = $prov;
        } else {
            unset($destinatario['prov']);
        }

        // SDI o PEC — priorità: Codice SDI → PEC → 0000000
        $sdi_codice = trim((string)$this->getCustomFieldValue('client', $this->IT_CLIENTE_SDI_CODICE_ID));
        $sdi_pec    = trim((string)$this->getCustomFieldValue('client', $this->IT_CLIENTE_SDI_PEC_ID));

        if (!empty($sdi_codice) && preg_match('/^[A-Z0-9]{6,7}$/i', $sdi_codice)) {
            $destinatario['sdi_codice'] = strtoupper(str_pad($sdi_codice, 7, '0', STR_PAD_LEFT));
        } elseif (!empty($sdi_pec) && filter_var($sdi_pec, FILTER_VALIDATE_EMAIL)) {
            $destinatario['sdi_pec'] = $sdi_pec;
        } else {
            $destinatario['sdi_codice'] = '0000000';
        }

        log_message('debug', 'Destinatario array: ' . print_r($destinatario, true));

        $fatturapa->set_destinatario($destinatario);

        // Per persona fisica, aggiungi Nome e Cognome manualmente
        if ($is_persona_fisica) {
            // Rimuovi Denominazione (non serve per persona fisica)
            $anagrafica_dest = &$fatturapa->get_node('FatturaElettronicaHeader/CessionarioCommittente/DatiAnagrafici/Anagrafica');
            
            if (isset($anagrafica_dest['Denominazione'])) {
                unset($anagrafica_dest['Denominazione']);
            }
            
            // Aggiungi Nome e Cognome
            $fatturapa->set_node('FatturaElettronicaHeader/CessionarioCommittente/DatiAnagrafici/Anagrafica/Nome', $nome);
            $fatturapa->set_node('FatturaElettronicaHeader/CessionarioCommittente/DatiAnagrafici/Anagrafica/Cognome', $cognome);
            
            log_message('debug', "Persona fisica: Nome={$nome}, Cognome={$cognome}");
        }

        // === INTESTAZIONE FATTURA ===
        $tipoDocumento = ($this->invoice->invoice_sign < 0) ? 'TD04' : 'TD01'; // TD04 = Nota di credito
        
        $fatturapa->set_intestazione([
            'tipodoc' => $tipoDocumento,
            'valuta'  => $this->currencyCode,
            'data'    => $this->invoice->invoice_date_created,
            'numero'  => $this->invoice->invoice_number,
        ]);

        // === RIGHE DETTAGLIO ===
        $natura_iva0 = $this->getCustomFieldValue('user', $this->IT_UTENTE_NATURA_IVA0_ID);
        $natura_iva0 = $natura_iva0 ? trim($natura_iva0) : null;

        log_message('debug', 'IT_UTENTE_NATURA_IVA0_ID: ' . ($this->IT_UTENTE_NATURA_IVA0_ID ?: 'NULL'));
        log_message('debug', 'natura_iva0 recuperato: ' . ($natura_iva0 ?: 'NULL'));

        // Valida il formato della natura
        if ($natura_iva0 && !preg_match('/^N[1-7](\.\d+)?$/', $natura_iva0)) {
            throw new Exception('Formato Natura IVA non valido: "' . $natura_iva0 . '". Usa formato N1, N2.1, N2.2, ecc.');
        }

        foreach ($this->items as $n => $item) {
            $tax_percent = floatval($item->item_tax_rate_percent);
            
            $riga = [
                'num'         => ++$n,
                'descrizione' => $item->item_name . ($item->item_description ? PHP_EOL . $item->item_description : ''),
                'prezzo'      => FatturaPA::dec(floatval(($item->item_total - $item->item_tax_total) / $item->item_quantity)),
                'qta'         => FatturaPA::dec(floatval($item->item_quantity)),
                'importo'     => FatturaPA::dec(floatval($item->item_total - $item->item_tax_total)),
                'perciva'     => FatturaPA::dec($tax_percent),
            ];
            
            // Aggiungi natura SOLO se IVA = 0
            if ($tax_percent == 0) {
                if (empty($natura_iva0)) {
                    throw new Exception('Natura IVA 0% mancante (user_id: ' . $this->invoice->user_id . ', field_id: ' . $this->IT_UTENTE_NATURA_IVA0_ID . ')');
                }
                $riga['natura_iva0'] = $natura_iva0;
                log_message('debug', "Riga {$n}: aggiunta natura {$natura_iva0}");
            }
            
            $fatturapa->add_riga($riga);
        }

        // === TOTALI AUTOMATICI ===
        $merge = [];

        if ($this->notax) {
            $merge['natura'] = $natura_iva0;
        } else {
            $merge['esigiva'] = 'I';
        }

        $totale = $fatturapa->set_auto_totali($merge, ['autobollo' => true]);

        log_message('debug', 'Totale calcolato: ' . $totale);

        // === PAGAMENTO ===
        if ($this->invoice->invoice_balance != 0) {
            $payment_method = $this->invoice->payment_method;
            $modalita_pagamento = $this->IT_METODO_PAGAMENTO[$payment_method] ?? 'MP05'; // Default: bonifico
            
            $pagamento_dati = [
                'condizioni' => "TP02" // Pagamento completo
            ];
            
            $pagamento_dettaglio = [
                'modalita' => $modalita_pagamento,
                'totale'   => FatturaPA::dec($this->invoice->invoice_balance),
                'scadenza' => $this->invoice->invoice_date_due,
            ];
            
            // Aggiungi IBAN solo per bonifico
            if ($modalita_pagamento === 'MP05' && $this->invoice->user_iban) {
                $pagamento_dettaglio['iban'] = $this->noSpace($this->invoice->user_iban);

                if (!empty($this->invoice->user_bic)) {
                    $pagamento_dettaglio['bic'] = $this->noSpace($this->invoice->user_bic);
                }
            }
            
            $fatturapa->set_pagamento($pagamento_dati, [$pagamento_dettaglio]);
        }

        // === CONTATTI ===
        $tel = $this->invoice->user_phone ?: ($this->invoice->user_mobile ?: null);
        if ($tel) {
            $tel_normalizzato = $this->normalizeTelefono($tel);
            if (!empty($tel_normalizzato) && strlen($tel_normalizzato) >= 5) {
                $fatturapa->set_node('FatturaElettronicaHeader/CedentePrestatore/Contatti/Telefono', $tel_normalizzato);
            }
        }
        
        if ($this->invoice->user_email) {
            $fatturapa->set_node('FatturaElettronicaHeader/CedentePrestatore/Contatti/Email', $this->invoice->user_email);
        }
        
        if ($this->invoice->user_fax) {
            $fatturapa->set_node('FatturaElettronicaHeader/CedentePrestatore/Contatti/Fax', $this->invoice->user_fax);
        }

        // === NOME FILE CON PROGRESSIVO ===
        $progressivo = $this->getCustomFieldValue('user', $this->IT_UTENTE_PROGR_XML_ID);

        if ($progressivo) {
            $nome_file_xml     = $fatturapa->filename($progressivo);
            $nuovo_progressivo = str_pad((int)$progressivo + 1, 5, '0', STR_PAD_LEFT);
            $this->updateCustomFieldValue('user', $this->IT_UTENTE_PROGR_XML_ID, $nuovo_progressivo);
        } else {
            $nome_file_xml = $fatturapa->filename($this->invoice->invoice_number);
        }

        if (!str_ends_with(strtolower($nome_file_xml), '.xml')) {
            $nome_file_xml .= '.xml';
        }

        // === GENERA E SALVA XML ===
        $xml = $fatturapa->get_xml();
        $path = UPLOADS_TEMP_FOLDER . $nome_file_xml;

        if (IP_DEBUG) {
            $file = fopen($path, 'w');
            fwrite($file, $xml);
            fclose($file);
        } else {
            $doc = new DOMDocument();
            $doc->formatOutput = false;
            $doc->loadXML($xml);
            $doc->save($path);
        }

        return $nome_file_xml;
    }

    /**
     * Aggiorna il valore di un custom field
     * 
     * @param string $type 'user' o 'client' o 'invoice'
     * @param int $field_id ID del custom field
     * @param string $value Nuovo valore
     * @param int|null $record_id ID del record
     */
    protected function updateCustomFieldValue($type, $field_id, $value, $record_id = null)
    {
        $CI = & get_instance();
        
        if ($type === 'user') {
            $CI->load->model('custom_fields/mdl_user_custom');
            $user_id = $record_id ?: $this->invoice->user_id;
            
            $CI->db->where('user_id', $user_id);
            $CI->db->where('user_custom_fieldid', $field_id);
            $CI->db->update('ip_user_custom', ['user_custom_fieldvalue' => $value]);
        }
    }

    // Eliminare gli spazi
    public function noSpace($str): string
    {
        return strtr($str, [' ' => '']);
    }

    /**
     * @return float[]|int[]
     */
    public function itemsSubtotalGroupedByTaxPercent(): array
    {
        $result = [];
        foreach ($this->items as $item) {
            if ($item->item_tax_rate_percent == 0) {
                continue;
            }

            if ( ! isset($result[$item->item_tax_rate_percent])) {
                $result[$item->item_tax_rate_percent] = 0;
            }

            $result[$item->item_tax_rate_percent] += $item->item_subtotal;
        }

        return $result;
    }

    /**
     * Normalizza numero di telefono per FatturaPA (max 12 caratteri)
     */
    protected function normalizeTelefono($telefono)
    {
        if (empty($telefono)) {
            return '';
        }
        
        // Rimuovi spazi, trattini, parentesi, punti
        $tel = preg_replace('/[\s\-\(\)\.\/]/', '', $telefono);
        
        // Rimuovi prefisso +39 se presente (già indicato in CountryCode)
        $tel = preg_replace('/^\+?39/', '', $tel);
        
        // Rimuovi altri prefissi internazionali comuni
        $tel = preg_replace('/^\+/', '', $tel);
        
        // Tronca a 12 caratteri se necessario
        if (strlen($tel) > 12) {
            $tel = substr($tel, 0, 12);
            log_message('warning', "Telefono troncato a 12 caratteri: {$telefono} -> {$tel}");
        }
        
        return $tel;
    }

}

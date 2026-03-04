<?php

if ( ! defined('BASEPATH')) {
    exit('No direct script access allowed');
}

/*
 * InvoicePlane
 *
 * @author      InvoicePlane Developers & Contributors
 * @copyright   Copyright (c) 2012 - 2018 InvoicePlane.com
 * @license     https://invoiceplane.com/license.txt
 * @link        https://invoiceplane.com
 */

#[AllowDynamicProperties]
class Invoices extends Admin_Controller
{
    /**
     * Invoices constructor.
     */
    public function __construct()
    {
        parent::__construct();

        $this->load->model('mdl_invoices');
    }

    public function index(): void
    {
        // Display all invoices by default
        redirect('invoices/status/all');
    }

    /**
     * @param int $page
     */
    public function status(string $status = 'all', $page = 0): void
    {
        // Determine which group of invoices to load
        switch ($status) {
            case 'draft':
                $this->mdl_invoices->is_draft();
                break;
            case 'sent':
                $this->mdl_invoices->is_sent();
                break;
            case 'viewed':
                $this->mdl_invoices->is_viewed();
                break;
            case 'paid':
                $this->mdl_invoices->is_paid();
                break;
            case 'overdue':
                $this->mdl_invoices->is_overdue();
                break;
        }

        $this->mdl_invoices->paginate(site_url('invoices/status/' . $status), $page);
        $invoices = $this->mdl_invoices->result();

        $this->layout->set(
            [
                'invoices'           => $invoices,
                'status'             => $status,
                'filter_display'     => true,
                'filter_placeholder' => trans('filter_invoices'),
                'filter_method'      => 'filter_invoices',
                'invoice_statuses'   => $this->mdl_invoices->statuses(),
            ]
        );

        $this->layout->buffer('content', 'invoices/index');
        $this->layout->render();
    }

    public function archive(): void
    {
        $invoice_array = $this->mdl_invoices->get_archives(0);
        $this->layout->set(
            [
                'filter_display'     => true,
                'filter_placeholder' => trans('filter_archives'),
                'filter_method'      => 'filter_archives',
                'invoices_archive'   => $invoice_array,
            ]
        );
        $this->layout->buffer('content', 'invoices/archive');
        $this->layout->render();
    }

    public function download($invoice): void
    {
        $safeBaseDir = realpath(UPLOADS_ARCHIVE_FOLDER);

        $fileName = urldecode(basename($invoice)); // Strip directory traversal sequences
        $filePath = realpath($safeBaseDir . DIRECTORY_SEPARATOR . $fileName);

        if ($filePath === false || ! str_starts_with($filePath, $safeBaseDir)) {
            log_message('error', 'Invalid file access attempt: ' . $fileName);
            show_404();

            return;
        }

        if ( ! file_exists($filePath)) {
            log_message('error', 'While downloading: File not found: ' . $filePath);
            show_404();

            return;
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . basename($filePath) . '"');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        exit;
    }

    public function view($invoice_id): void
    {
        $this->load->model(
            [
                'invoices/mdl_items',
                'invoices/mdl_invoice_tax_rates',
                'tax_rates/mdl_tax_rates',
                'payment_methods/mdl_payment_methods',
                'custom_fields/mdl_custom_fields',
                'custom_values/mdl_custom_values',
                'custom_fields/mdl_invoice_custom',
                'units/mdl_units',
                'upload/mdl_uploads',
            ]
        );
        $this->load->helper(['custom_values', 'dropzone', 'e-invoice']);
        $this->load->module('payments');

        $this->db->reset_query();

        /*$invoice_custom = $this->mdl_invoice_custom->where('invoice_id', $invoice_id)->get();

        if ($invoice_custom->num_rows()) {
            $invoice_custom = $invoice_custom->row();

            unset($invoice_custom->invoice_id, $invoice_custom->invoice_custom_id);

            foreach ($invoice_custom as $key => $val) {
                $this->mdl_invoices->set_form_value('custom[' . $key . ']', $val);
            }
        }*/

        $fields  = $this->mdl_invoice_custom->by_id($invoice_id)->get()->result();
        $invoice = $this->mdl_invoices->get_by_id($invoice_id);

        if ( ! $invoice) {
            show_404();
        }
        $is_credit_invoice = (isset($invoice->invoice_sign) && $invoice->invoice_sign == -1);
        $custom_fields = $this->mdl_custom_fields->by_table('ip_invoice_custom')->get()->result();
        $custom_values = [];
        foreach ($custom_fields as $custom_field) {
            if (in_array($custom_field->custom_field_type, $this->mdl_custom_values->custom_value_fields())) {
                $values                                        = $this->mdl_custom_values->get_by_fid($custom_field->custom_field_id)->result();
                $custom_values[$custom_field->custom_field_id] = $values;
            }
        }

        foreach ($custom_fields as $cfield) {
            foreach ($fields as $fvalue) {
                if ($fvalue->invoice_custom_fieldid == $cfield->custom_field_id) {
                    // TODO: Hackish, may need a better optimization
                    $this->mdl_invoices->set_form_value(
                        'custom[' . $cfield->custom_field_id . ']',
                        $fvalue->invoice_custom_fieldvalue
                    );
                    break;
                }
            }
        }

        // Check whether there are payment custom fields
        $payment_cf       = $this->mdl_custom_fields->by_table('ip_payment_custom')->get();
        $payment_cf_exist = ($payment_cf->num_rows() > 0) ? 'yes' : 'no';
        // Get Items
        $items = $this->mdl_items->where('invoice_id', $invoice_id)->get()->result();
        // Get eInvoice library name and user checks
        $einvoice = get_einvoice_usage($invoice, $items);
        // Activate 'Change_user' if admin users > 1  (get the sum of user type = 1 & active)
        $change_user = $this->db->from('ip_users')->where(['user_type' => 1, 'user_active' => 1])->select_sum('user_type')->get()->row();
        $change_user = $change_user->user_type > 1;

        $this->layout->set(
            [
                'invoice'           => $invoice,
                'items'             => $items,
                'invoice_id'        => $invoice_id,
                'is_credit_invoice' => $is_credit_invoice,
                'einvoice'          => $einvoice,
                'change_user'       => $change_user,
                'tax_rates'         => $this->mdl_tax_rates->get()->result(),
                'invoice_tax_rates' => $this->mdl_invoice_tax_rates->where('invoice_id', $invoice_id)->get()->result(),
                'units'             => $this->mdl_units->get()->result(),
                'payment_methods'   => $this->mdl_payment_methods->get()->result(),
                'custom_fields'     => $custom_fields,
                'custom_values'     => $custom_values,
                'custom_js_vars'    => [
                    'currency_symbol'           => get_setting('currency_symbol'),
                    'currency_symbol_placement' => get_setting('currency_symbol_placement'),
                    'decimal_point'             => get_setting('decimal_point'),
                ],
                'invoice_statuses'   => $this->mdl_invoices->statuses(),
                'payment_cf_exist'   => $payment_cf_exist,
                'legacy_calculation' => config_item('legacy_calculation'),
            ]
        );

        $this->layout->buffer(
            [
                ['modal_delete_invoice', 'invoices/modal_delete_invoice'],
                ['modal_add_invoice_tax', 'invoices/modal_add_invoice_tax'],
                ['modal_add_payment', 'payments/modal_add_payment'],
                ['content', 'invoices/view' . ($invoice->sumex_id ? '_sumex' : '')],
            ]
        );

        $this->layout->render();
    }

    public function delete($invoice_id): void
    {
        // Get the status of the invoice
        $invoice        = $this->mdl_invoices->get_by_id($invoice_id);
        $invoice_status = $invoice->invoice_status_id;

        if ($invoice_status == 1 || $this->config->item('enable_invoice_deletion') === true) {
            // If invoice refers to tasks, mark those tasks back to 'Complete'
            $this->load->model('tasks/mdl_tasks');
            $tasks = $this->mdl_tasks->update_on_invoice_delete($invoice_id);

            // Delete the invoice
            $this->mdl_invoices->delete($invoice_id);
        } else {
            // Add alert that invoices can't be deleted
            $this->session->set_flashdata('alert_error', trans('invoice_deletion_forbidden'));
        }

        // Redirect to invoice index
        redirect('invoices/index');
    }

    /**
     * @param      $invoice_id
     * @param bool $stream
     */
    public function generate_pdf($invoice_id, $stream = true, $invoice_template = null): void
    {
        $this->load->helper('pdf');

        if (get_setting('mark_invoices_sent_pdf') == 1) {
            $this->mdl_invoices->generate_invoice_number_if_applicable($invoice_id);
            $this->mdl_invoices->mark_sent($invoice_id);
        }

        generate_invoice_pdf($invoice_id, $stream, $invoice_template, null);
    }

    /**
     * General XML Fattura Eletronica e lancia il download dell'XML
     * @param int $invoice_id
     */
    public function generate_xml($invoice_id)
    {
        // Carica helper
        $this->load->helper('e-invoice');
        $this->load->model('invoices/mdl_items');
        $this->load->model('users/mdl_users');
        $this->load->model('custom_fields/mdl_user_custom');
        $this->load->model('custom_fields/mdl_client_custom');
        
        // Ottieni invoice e items
        $invoice = $this->mdl_invoices->get_by_id($invoice_id);
        $items = $this->mdl_items->where('invoice_id', $invoice_id)->get()->result();
        
        if (!$invoice) {
            show_404();
            return;
        }
        
        $user_id = $invoice->user_id;
        $client_id = $invoice->client_id;
        
        // === DEBUG: Verifica ENV variables ===
        log_message('debug', '=== GENERATE_XML START ===');
        log_message('debug', "Invoice ID: {$invoice_id}");
        log_message('debug', "User ID: {$user_id}");
        log_message('debug', "Client ID: {$client_id}");
        log_message('debug', 'IT_UTENTE_REGIMEFISC_ID: ' . (env('IT_UTENTE_REGIMEFISC_ID') ?: 'NON IMPOSTATO'));
        log_message('debug', 'IT_UTENTE_NATURA_IVA0_ID: ' . (env('IT_UTENTE_NATURA_IVA0_ID') ?: 'NON IMPOSTATO'));
        log_message('debug', 'IT_CLIENTE_SDI_CODICE_ID: ' . (env('IT_CLIENTE_SDI_CODICE_ID') ?: 'NON IMPOSTATO'));
        log_message('debug', 'IT_CLIENTE_SDI_PEC_ID: ' . (env('IT_CLIENTE_SDI_PEC_ID') ?: 'NON IMPOSTATO'));
        
        // Recupera custom fields UTENTE
        $regimefisc = null;
        $natura_iva0 = null;
        
        if (env('IT_UTENTE_REGIMEFISC_ID')) {
            $field_id = env('IT_UTENTE_REGIMEFISC_ID');
            log_message('debug', "Cerco regime fiscale - field_id: {$field_id}, user_id: {$user_id}");
            
            $user_custom = $this->mdl_user_custom->by_id($user_id)
                ->where('user_custom_fieldid', $field_id)
                ->get()->row();
            
            log_message('debug', 'Query user_custom result: ' . print_r($user_custom, true));
            
            if ($user_custom && !empty($user_custom->user_custom_fieldvalue)) {
                $regimefisc = $user_custom->user_custom_fieldvalue;
                log_message('debug', "Regime fiscale TROVATO: {$regimefisc}");
            } else {
                log_message('error', 'REGIME FISCALE NON TROVATO per user_id: ' . $user_id);
            }
        } else {
            log_message('error', 'ENV IT_UTENTE_REGIMEFISC_ID non impostata');
        }
        
        if (env('IT_UTENTE_NATURA_IVA0_ID')) {
            $user_custom = $this->mdl_user_custom->by_id($user_id)
                ->where('user_custom_fieldid', env('IT_UTENTE_NATURA_IVA0_ID'))
                ->get()->row();
            if ($user_custom && !empty($user_custom->user_custom_fieldvalue)) {
                $natura_iva0 = $user_custom->user_custom_fieldvalue;
                log_message('debug', "Natura IVA0 trovata: {$natura_iva0}");
            }
        }
        
        // Recupera custom fields CLIENTE
        $sdi_codice = null;
        $sdi_pec = null;
        
        if (env('IT_CLIENTE_SDI_CODICE_ID')) {
            $client_custom = $this->mdl_client_custom->by_id($client_id)
                ->where('client_custom_fieldid', env('IT_CLIENTE_SDI_CODICE_ID'))
                ->get()->row();
            if ($client_custom && !empty($client_custom->client_custom_fieldvalue)) {
                $sdi_codice = $client_custom->client_custom_fieldvalue;
                log_message('debug', "SDI Codice trovato: {$sdi_codice}");
            }
        }
        
        if (env('IT_CLIENTE_SDI_PEC_ID')) {
            $client_custom = $this->mdl_client_custom->by_id($client_id)
                ->where('client_custom_fieldid', env('IT_CLIENTE_SDI_PEC_ID'))
                ->get()->row();
            if ($client_custom && !empty($client_custom->client_custom_fieldvalue)) {
                $sdi_pec = $client_custom->client_custom_fieldvalue;
                log_message('debug', "SDI PEC trovata: {$sdi_pec}");
            }
        }
        
        log_message('debug', 'Valori finali - Regime: ' . ($regimefisc ?: 'NULL') . ', Natura IVA0: ' . ($natura_iva0 ?: 'NULL'));
        
        // VERIFICA: Se regimefisc è NULL, blocca con errore
        if (empty($regimefisc)) {
            $error_msg = "Regime Fiscale mancante. Verifica le impostazioni utente.";
            log_message('error', $error_msg);
            log_message('error', 'Verifica: 1) ENV IT_UTENTE_REGIMEFISC_ID 2) Custom field creato 3) Valore compilato');
            
            $this->session->set_flashdata('alert_error', $error_msg);
            redirect('invoices/view/' . $invoice_id);
            return;
        }
        
        // Determina template XML
        $xml_lib = 'Fatturapav12';
        
        // Opzioni (SENZA default)
        $options = [
            'regimefisc' => $regimefisc,
            'natura_iva0' => $natura_iva0,
            'sdi_codice' => $sdi_codice,
            'sdi_pec' => $sdi_pec,
        ];
        
        log_message('debug', 'Options: ' . print_r($options, true));
        
        // Nome file
        $user_vat_clean = str_replace(' ', '', $invoice->user_vat_id);
        $invoice_num_clean = str_replace(['/', '-', ' '], '', $invoice->invoice_number);
        $filename = 'IT' . $user_vat_clean . '_' . $invoice_num_clean;
        
        log_message('debug', "XML template: {$xml_lib}, filename: {$filename}");
        
        // Genera XML
        try {
            $xml_file = generate_xml_invoice_file($invoice, $items, $xml_lib, $filename, $options);
            
            log_message('debug', "XML generato con successo: {$xml_file}");
            
            // Forza download
            $this->load->helper('download');
            $xml_content = file_get_contents($xml_file);
            force_download($filename . '.xml', $xml_content);
            
            // Cleanup
            if (file_exists($xml_file)) {
                unlink($xml_file);
            }
            
        } catch (Exception $e) {
            log_message('error', 'Errore generazione FatturaPA: ' . $e->getMessage());
            log_message('error', 'Stack trace: ' . $e->getTraceAsString());
            
            $this->session->set_flashdata('alert_error', 'Errore generazione XML: ' . $e->getMessage());
            redirect('invoices/view/' . $invoice_id);
        }
        
        log_message('debug', '=== GENERATE_XML END ===');
    }
    
    /**
     * @param $invoice_id
     */
    public function generate_zugferd_xml($invoice_id)
    {
        $invoice = $this->mdl_invoices->get_by_id($invoice_id);
        if ( ! $invoice) {
            show_404();
        }

        $this->load->model('invoices/mdl_items');
        $items = $this->mdl_items->where('invoice_id', $invoice_id)->get()->result();

        $this->load->helper('e-invoice'); // eInvoicing++
        $einvoice = get_einvoice_usage($invoice, $items, false);
        if ( ! $einvoice->user) {
            show_404();
        }

        // eInvoice library to Generate the appropriate UBL/CII or false
        $xml_id    = $einvoice->name; // $invoice->client_einvoicing_version
        $options   = [];
        $generator = $xml_id;
        $path      = APPPATH . 'helpers/XMLconfigs/';
        if ($xml_id && file_exists($path . $xml_id . '.php') && include $path . $xml_id . '.php') {
            $embed_xml = $xml_setting['embedXML'];
            $XMLname   = $xml_setting['XMLname'];
            $options   = (empty($xml_setting['options']) ? $options : $xml_setting['options']); // Optional
            $generator = (empty($xml_setting['generator']) ? $generator : $xml_setting['generator']); // Optional
        }

        if (isset($is_credit_invoice) && $is_credit_invoice) {
            $filename = trans('credit_invoice');
        } else {
            $filename = trans('invoice');
        }
        $filename .= '_' . str_replace(['\\', '/'], '_', $invoice->invoice_number); 
        $path     = generate_xml_invoice_file($invoice, $items, $generator, $filename, $options);
        $this->output->set_content_type('text/xml');
        $this->output->set_output(file_get_contents($path));
        unlink($path);
    }

    public function generate_sumex_pdf($invoice_id): void
    {
        $this->load->helper('pdf');

        generate_invoice_sumex($invoice_id);
    }

    public function generate_sumex_copy($invoice_id): void
    {
        $this->load->model('invoices/mdl_items');
        $this->load->library('Sumex', [
            'invoice' => $this->mdl_invoices->get_by_id($invoice_id),
            'items'   => $this->mdl_items->where('invoice_id', $invoice_id)->get()->result(),
            'options' => [
                'copy'   => '1',
                'storno' => '0',
            ],
        ]);

        $this->output->set_content_type('application/pdf');
        $this->output->set_output($this->sumex->pdf());
    }
	
    //---it---inizio
    public function preview_pdf($invoice_id, $stream = TRUE, $invoice_template = NULL)
    {
    	$this->load->helper('pdf');
    	
    	generate_invoice_pdf($invoice_id, $stream, $invoice_template, NULL, TRUE);
    }
    //---it---fine
    
    /**
     * @param $invoice_id
     * @param $invoice_tax_rate_id
     */
    public function delete_invoice_tax($invoice_id, $invoice_tax_rate_id)
    {
        $this->load->model('invoices/mdl_invoice_tax_rates');
        $this->mdl_invoice_tax_rates->delete($invoice_tax_rate_id);

        $this->load->model('invoices/mdl_invoice_amounts');
        $global_discount['item'] = $this->mdl_invoice_amounts->get_global_discount($invoice_id);
        // Recalculate invoice amounts
        $this->mdl_invoice_amounts->calculate($invoice_id, $global_discount);

        redirect('invoices/view/' . $invoice_id);
    }

    public function recalculate_all_invoices(): void
    {
        $this->db->select('invoice_id');
        $invoice_ids = $this->db->get('ip_invoices')->result();

        $this->load->model('invoices/mdl_invoice_amounts');

        foreach ($invoice_ids as $invoice_id) {
            $global_discount['item'] = $this->mdl_invoice_amounts->get_global_discount($invoice_id->invoice_id);
            // Recalculate invoice amounts
            $this->mdl_invoice_amounts->calculate($invoice_id->invoice_id, $global_discount);
        }
    }
}

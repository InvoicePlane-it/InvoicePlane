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
 *
 * eInvoicing add-ons by Verony
 */
/**
 * Create a PDF.
 *
 * @param      $html
 * @param      $filename
 * @param bool $stream           (show or download)
 * @param bool $embed_xml        (eInvoicing)
 * @param null $associated_files (eInvoicing)
 *
 * @return string
 *
 * @throws \Mpdf\MpdfException
 */
function pdf_create(
    $html,
    string $filename,
    bool $stream = true,
    $password = null,
    $isInvoice = null,
    $is_guest = null,
    bool $embed_xml = false,
    ?array $associated_files = []
) {
    $CI = & get_instance();

    // ---it---inizio
    // Speciale motore stampa dompdf: primo motore stampa FI, poi tolto dalla versione originale e mantenuto nella versione italiana.
    // Questo motore PDF, infatti, mantiene il risultato visualizzato nell'anteprima PDF (a differenza del nuovo motore mPDF).
    if ($CI->mdl_settings->setting('it_print_engine') == 'dompdf')
    {
    	return pdf_create_dompdf($html, $filename, $stream);
    }
    // ---it---fine
    
    // Get the invoice from the archive if available
    $invoice_array = [];

    // mPDF loading
    $mpdf = new \Mpdf\Mpdf([
        'tempDir' => UPLOADS_TEMP_MPDF_FOLDER,
    ]);

    // mPDF configuration
    $mpdf->useAdobeCJK      = true;
    $mpdf->autoScriptToLang = true;
    $mpdf->autoVietnamese   = true;
    $mpdf->autoArabic       = true;
    $mpdf->autoLangToFont   = true;
    // Page number in footer by {PAGENO} See mpdf.github.io/paging/page-numbering.html
    $mpdf->setFooter('<p align="center">' . str_replace('_', ' ', $filename) . ' - ' . trans('page') . ' {PAGENO} / {nbpg}</p>');

    if (IP_DEBUG) {
        // Enable image error logging
        $mpdf->showImageErrors = true;
    }

    // eInvoicing: Include (embedded) XML if enabled for the client
    if ($embed_xml) {
        $CI->load->helper('e-invoice');
        // mpdf only creates PDF/A-1b files and cannot create the required PDF/A-3b files!
        $mpdf->pdf_version = '1.7';
        $mpdf->PDFA        = true;
        $mpdf->PDFAauto    = true;
        $mpdf->SetAssociatedFiles($associated_files);
        $mpdf->SetAdditionalXmpRdf(include_rdf($associated_files[0]['name']));
    }

    // Set a password if set for the voucher
    if ( ! empty($password)) {
        $mpdf->SetProtection(['copy', 'print'], $password, $password);
    }

    // Check if the archive folder is available
    if ( ! is_dir(UPLOADS_ARCHIVE_FOLDER) || is_link(UPLOADS_ARCHIVE_FOLDER) && ( ! mkdir(UPLOADS_ARCHIVE_FOLDER, '0777') && ! is_dir(UPLOADS_ARCHIVE_FOLDER))) {
        throw new \RuntimeException(sprintf('Directory "%s" was not created', UPLOADS_ARCHIVE_FOLDER));
    }

    // Set the footer if voucher is invoice and if set in settings
    if ($isInvoice && ! empty($CI->mdl_settings->settings['pdf_invoice_footer'])) {
        $mpdf->setAutoBottomMargin = 'stretch';
        $mpdf->SetHTMLFooter('<div id="footer">' . $CI->mdl_settings->settings['pdf_invoice_footer'] . '</div>');
    }

    // Set the footer if voucher is quote and if set in settings
    if ( ! $isInvoice && ! empty($CI->mdl_settings->settings['pdf_quote_footer'])) {
        $mpdf->setAutoBottomMargin = 'stretch';
        $mpdf->SetHTMLFooter('<div id="footer">' . $CI->mdl_settings->settings['pdf_quote_footer'] . '</div>');
    }

    // Watermark (eInvoicing++ PDFA and PDFX do not permit transparency, so mPDF does not allow Watermarks!)
    if ( ! $embed_xml && get_setting('pdf_watermark')) {
        $mpdf->showWatermarkText = true;
    }

    $mpdf->WriteHTML((string) $html);

    if ($isInvoice) {
        $pdfFiles = glob(UPLOADS_ARCHIVE_FOLDER . '*' . $filename . '.pdf');

        foreach ($pdfFiles as $file) {
            $invoice_array[] = $file;
        }

        if ($invoice_array !== [] && null !== $is_guest) {
            rsort($invoice_array);

            if ($stream) {
                return $mpdf->Output($filename . '.pdf', 'I');
            }

            return $invoice_array[0];
        }

        $archived_file = UPLOADS_ARCHIVE_FOLDER . date('Y-m-d') . '_' . $filename . '.pdf';
        $mpdf->Output($archived_file, 'F');

        if ($stream) {
            return $mpdf->Output($filename . '.pdf', 'I');
        }

        return $archived_file;
    }

    // If $stream is true (default) the PDF will be displayed directly in the browser
    // otherwise will be returned as a download
    if ($stream) {
        return $mpdf->Output($filename . '.pdf', 'I');
    }

    $mpdf->Output(UPLOADS_TEMP_FOLDER . $filename . '.pdf', 'F');

    return UPLOADS_TEMP_FOLDER . $filename . '.pdf';
}

// ---it---inizio Utilizza ancora dompdf: mpdf d� problemi (test con modello fattura s2 software)
/*
 * FusionInvoice
 *
 * A free and open source web based invoicing system
 *
 * @package		FusionInvoice
 * @author		Jesse Terry
 * @copyright	Copyright (c) 2012 - 2013, Jesse Terry
 * @license		http://www.fusioninvoice.com/license.txt
 * @link		http://www.fusioninvoice.
 *
 */
function pdf_create_dompdf($html, $filename, $stream = TRUE) {
	
	require_once(APPPATH . 'helpers/dompdf/dompdf_config.inc.php');
	
	$dompdf = new DOMPDF();
	
	$dompdf->load_html($html);
	
	$dompdf->set_paper('a4');	//---it---
	$dompdf->render();
	
	if ($stream) {
		
		$dompdf->stream($filename . '.pdf');
		
	}
	
	else {
		
		$CI =& get_instance();
		
		$CI->load->helper('file');
		
		write_file('./uploads/temp/' . $filename . '.pdf', $dompdf->output());
		
		return './uploads/temp/' . $filename . '.pdf';
	}
	
}
//---it---fine
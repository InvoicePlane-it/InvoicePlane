<div class="table-responsive">
    <table class="table table-hover table-striped">

        <thead>
        <tr>
            <th><<?php if (isset($is_credit_invoice) && $is_credit_invoice) { _trans('credit_invoice'); } else { _trans('invoice'); } ?></th>
            <th><?php _trans('created'); ?></th>
        </tr>
        </thead>

        <tbody>
        <?php foreach ($invoices_archive as $invoice) {
            ?>
            <tr>
                <td>
                    <a href="<?php echo site_url('invoices/download/' . basename($invoice)); ?>"
                       title="<?php if (isset($is_credit_invoice) && $is_credit_invoice) { _trans('credit_invoice'); } else { _trans('invoice'); } ?>">
                        <?php echo basename($invoice); ?>
                    </a>
                </td>

                <td>
                    <?php echo date('F d Y H:i:s.', filemtime($invoice)); ?>
                </td>

            </tr>
        <?php } ?>
        </tbody>

    </table>
</div>

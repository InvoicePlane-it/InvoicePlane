# Added for versioning
ALTER TABLE `ip_invoice_items`
    ADD COLUMN `item_discount_percent` DECIMAL(20,2) NOT NULL DEFAULT 0
    AFTER `item_discount_amount`;
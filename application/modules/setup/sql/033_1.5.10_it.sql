# ---it--- item_discount_percent added
ALTER TABLE `ip_invoice_items`
    ADD COLUMN `item_discount_percent` DECIMAL(20,2) DEFAULT NULL
    AFTER `item_discount_amount`;

-- Store the Peach result on each checkout (also auto-added by ensurePeachCheckoutsResultColumns).
-- Run once; MySQL errors with "Duplicate column name" if a column already exists.
ALTER TABLE peach_checkouts
    ADD COLUMN result_code VARCHAR(32) NULL AFTER status,
    ADD COLUMN result_description VARCHAR(255) NULL AFTER result_code,
    ADD COLUMN transaction_id VARCHAR(64) NULL AFTER result_description,
    ADD COLUMN payment_brand VARCHAR(32) NULL AFTER transaction_id,
    ADD COLUMN card_last4 VARCHAR(4) NULL AFTER payment_brand;



SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;



ALTER TABLE `payments`
    ADD COLUMN `order_id` INT(11) DEFAULT NULL AFTER `winner_id`,
    ADD COLUMN `gateway_response` TEXT DEFAULT NULL AFTER `transaction_id`,
    ADD COLUMN `refund_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `payment_status`,
    ADD COLUMN `refunded_at` DATETIME DEFAULT NULL AFTER `refund_amount`,
    ADD COLUMN `initiated_at` DATETIME DEFAULT NULL AFTER `created_at`,
    ADD KEY `idx_order` (`order_id`),
    ADD KEY `idx_payment_status` (`payment_status`),
    ADD KEY `idx_created` (`created_at`);

ALTER TABLE `payments`
    ADD CONSTRAINT `fk_payment_order`
    FOREIGN KEY (`order_id`)
    REFERENCES `orders`(`order_id`)
    ON DELETE SET NULL;


UPDATE `payments` p
INNER JOIN `orders` o ON o.auction_id = p.auction_id
SET p.order_id = o.order_id
WHERE p.order_id IS NULL;



CREATE TABLE IF NOT EXISTS `payment_history` (
    `history_id` INT(11) NOT NULL AUTO_INCREMENT,
    `payment_id` INT(11) NOT NULL,
    `order_id` INT(11) DEFAULT NULL,

    `old_status` VARCHAR(30) DEFAULT NULL,
    `new_status` VARCHAR(30) NOT NULL,

    `note` VARCHAR(255) DEFAULT NULL,
    `actor_id` INT(11) DEFAULT NULL COMMENT 'user_id of who triggered the change (null = system)',
    `actor_type` ENUM('system','buyer','seller','admin','gateway') NOT NULL DEFAULT 'system',

    `ip_address` VARCHAR(45) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`history_id`),
    KEY `idx_payment` (`payment_id`),
    KEY `idx_order` (`order_id`),
    KEY `idx_created` (`created_at`),

    CONSTRAINT `fk_history_payment` FOREIGN KEY (`payment_id`) REFERENCES `payments`(`payment_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_history_order` FOREIGN KEY (`order_id`) REFERENCES `orders`(`order_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



INSERT INTO `payment_history` (payment_id, order_id, old_status, new_status, note, actor_type, created_at)
SELECT
    p.payment_id,
    p.order_id,
    NULL,
    p.payment_status,
    'Backfilled from existing payment record',
    'system',
    p.created_at
FROM `payments` p
WHERE NOT EXISTS (
    SELECT 1 FROM `payment_history` ph WHERE ph.payment_id = p.payment_id
);


ALTER TABLE `orders`
    ADD KEY IF NOT EXISTS `idx_seller_status` (`seller_id`, `order_status`),
    ADD KEY IF NOT EXISTS `idx_buyer_status`  (`buyer_id`, `order_status`);


ALTER TABLE `shipping_addresses`
    ADD COLUMN IF NOT EXISTS `province` VARCHAR(50) DEFAULT NULL AFTER `city`,
    ADD COLUMN IF NOT EXISTS `postal_code` VARCHAR(20) DEFAULT NULL AFTER `province`,
    ADD COLUMN IF NOT EXISTS `is_default` TINYINT(1) NOT NULL DEFAULT 0 AFTER `postal_code`,
    ADD COLUMN IF NOT EXISTS `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`;



CREATE TABLE IF NOT EXISTS `shipment_tracking` (
    `tracking_id` INT(11) NOT NULL AUTO_INCREMENT,
    `shipment_id` INT(11) NOT NULL,
    `order_id` INT(11) NOT NULL,

    `status` VARCHAR(30) NOT NULL,
    `location` VARCHAR(120) DEFAULT NULL,
    `note` VARCHAR(255) DEFAULT NULL,

    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`tracking_id`),
    KEY `idx_shipment` (`shipment_id`),
    KEY `idx_order` (`order_id`),
    KEY `idx_created` (`created_at`),

    CONSTRAINT `fk_tracking_shipment` FOREIGN KEY (`shipment_id`) REFERENCES `shipments`(`shipment_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_tracking_order` FOREIGN KEY (`order_id`) REFERENCES `orders`(`order_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



ALTER TABLE `shipments`
    ADD COLUMN IF NOT EXISTS `order_id` INT(11) DEFAULT NULL AFTER `auction_id`,
    ADD COLUMN IF NOT EXISTS `shipped_at` DATETIME DEFAULT NULL AFTER `tracking_number`,
    ADD COLUMN IF NOT EXISTS `estimated_delivery` DATE DEFAULT NULL AFTER `shipped_at`,
    ADD COLUMN IF NOT EXISTS `delivered_at` DATETIME DEFAULT NULL AFTER `estimated_delivery`,
    ADD COLUMN IF NOT EXISTS `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `status`;


UPDATE `shipments` s
INNER JOIN `orders` o ON o.auction_id = s.auction_id
SET s.order_id = o.order_id
WHERE s.order_id IS NULL;

ALTER TABLE `shipments`
    ADD KEY IF NOT EXISTS `idx_order` (`order_id`);



COMMIT;

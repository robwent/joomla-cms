ALTER TABLE `#__users` ADD COLUMN `otpKey` varchar(1000) NOT NULL DEFAULT '' COMMENT 'Two factor authentication encrypted keys';

ALTER TABLE `#__users` ADD COLUMN `otep` varchar(1000) NOT NULL DEFAULT '' COMMENT 'One time emergency passwords';
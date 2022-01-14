CREATE DATABASE IF NOT EXISTS worker
CHARACTER SET utf8mb4
COLLATE utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `worker`.`sites` (
    `id` INT(20) NOT NULL AUTO_INCREMENT ,
    `url` VARCHAR(255) NOT NULL ,
    `status` ENUM('NEW','PROCESSING','DONE','ERROR') NOT NULL DEFAULT 'NEW' ,
    `http_code` INT(6) NOT NULL DEFAULT '0' ,
    `worker` VARCHAR(255) NULL ,
    PRIMARY KEY (`id`)
                              ) ENGINE = InnoDB;

INSERT INTO `sites` VALUES (DEFAULT, 'http://google.com', DEFAULT, DEFAULT, DEFAULT),
                           (DEFAULT, 'http://www.reddit.com', DEFAULT, DEFAULT, DEFAULT);


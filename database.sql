-- ==========================================================================
-- Jowaina Son Boda Boda Community - database
-- In phpMyAdmin: open the "Import" tab, choose this file, click "Go".
-- ==========================================================================

CREATE DATABASE IF NOT EXISTS jowaina_son
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE jowaina_son;

CREATE TABLE IF NOT EXISTS riders (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    rider_id       VARCHAR(20)  DEFAULT NULL,                 -- e.g. JS-000001 (made after sign-up)
    full_name      VARCHAR(100) NOT NULL,
    phone          VARCHAR(15)  NOT NULL,                     -- stored as 07XXXXXXXX / 01XXXXXXXX
    email          VARCHAR(150) NOT NULL,
    password_hash  VARCHAR(255) NOT NULL,                     -- NEVER the real password
    status         ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
    role           ENUM('rider','admin') NOT NULL DEFAULT 'rider',
    created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_riders_phone (phone),
    UNIQUE KEY uq_riders_email (email),
    UNIQUE KEY uq_riders_rider_id (rider_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
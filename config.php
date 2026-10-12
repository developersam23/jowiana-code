<?php
/**
 * Database connection.
 * XAMPP defaults: host "localhost", user "root", empty password.
 * When you put the site online, create a NEW database user with a strong
 * password (never use "root"), and keep this file out of public view.
 *
 * IMPORTANT: this code must appear only ONCE in the file.
 * Do not add a closing ?> tag, and nothing (not even a blank line) before <?php.
 */

const DB_HOST = 'localhost';
const DB_NAME = 'jowaina_son';
const DB_USER = 'root';
const DB_PASS = '';

function db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,   // real prepared statements
        ]);
    }

    return $pdo;
}
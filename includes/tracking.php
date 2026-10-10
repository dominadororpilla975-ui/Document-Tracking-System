<?php

function generateTrackingNumber(PDO $pdo, ?string $createdAt = null): string
{
    $check = $pdo->prepare('SELECT 1 FROM reports WHERE tracking_number = :tracking_number LIMIT 1');
    $timestamp = $createdAt !== null ? strtotime($createdAt) : time();
    $year = date('Y', $timestamp === false ? time() : $timestamp);
    $alphabet = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    do {
        $suffix = '';
        for ($index = 0; $index < 5; $index++) {
            $suffix .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $trackingNumber = 'DOC-' . $year . '-' . $suffix;
        $check->execute(['tracking_number' => $trackingNumber]);
    } while ($check->fetchColumn());

    return $trackingNumber;
}

<?php

/**
 * Time-based pricing resolver for ticket price phases.
 */

namespace ORAS\Tickets\Domain\Pricing;

final class Price_Resolver
{
  /**
   * Resolve a ticket price based on ordered price phases.
   *
   * @param array    $ticket     Ticket data.
   * @param int|null $now_utc_ts Current UTC timestamp.
   *
   * @return array{
   *     price: string,
   *     phase_key: string|null,
   *     phase_label: string|null,
   *     phase_end_ts: int|null,
   * }
   */
  public static function resolve_ticket_price(array $ticket, ?int $now_utc_ts = null): array
  {
    $now = null === $now_utc_ts ? (int) current_time('timestamp', true) : (int) $now_utc_ts;
    $base_price = self::normalize_price_string($ticket['price'] ?? null);
    $base_price = null !== $base_price ? $base_price : '0.00';

    $phases = $ticket['price_phases'] ?? null;
    if (! is_array($phases) || empty($phases)) {
      return array(
        'price'        => $base_price,
        'phase_key'    => null,
        'phase_label'  => null,
        'phase_end_ts' => null,
      );
    }

    foreach ($phases as $phase) {
      if (! is_array($phase)) {
        continue;
      }

      $phase_price = self::normalize_price_string($phase['price'] ?? null);
      if (null === $phase_price) {
        continue;
      }

      $start_ts = self::parse_utc_datetime_to_ts($phase['start'] ?? null);
      $end_ts   = self::parse_utc_datetime_to_ts($phase['end'] ?? null);

      if (null !== $start_ts && $now < $start_ts) {
        continue;
      }

      if (null !== $end_ts && $now > $end_ts) {
        continue;
      }

      return array(
        'price'        => $phase_price,
        'phase_key'    => isset($phase['key']) && is_string($phase['key']) ? $phase['key'] : null,
        'phase_label'  => isset($phase['label']) && is_string($phase['label']) ? $phase['label'] : null,
        'phase_end_ts' => $end_ts,
      );
    }

    return array(
      'price'        => $base_price,
      'phase_key'    => null,
      'phase_label'  => null,
      'phase_end_ts' => null,
    );
  }

  /**
   * Parse a UTC datetime string to a timestamp.
   *
   * @param string|null $dt Datetime string.
   *
   * @return int|null
   */
  private static function parse_utc_datetime_to_ts(?string $dt): ?int
  {
    if (null === $dt || '' === trim($dt)) {
      return null;
    }

    $timestamp = strtotime($dt . ' UTC');
    if (false === $timestamp) {
      return null;
    }

    return (int) $timestamp;
  }

  /**
   * Normalize a numeric price value to a string with 2 decimals.
   *
   * @param mixed $value Price value.
   *
   * @return string|null
   */
  private static function normalize_price_string($value): ?string
  {
    if (is_string($value)) {
      $value = trim($value);
    }

    if ('' === $value || null === $value) {
      return null;
    }

    if (! is_numeric($value)) {
      return null;
    }

    return number_format((float) $value, 2, '.', '');
  }
}

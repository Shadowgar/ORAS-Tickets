<?php

namespace ORAS\Tickets\Domain;

if (! defined('ABSPATH')) {
	exit;
}

final class Ticket
{

	/** Stable internal ID for ticket row; never changes once created. */
	public string $ticket_key;

	/** Display name shown to customers/admin. */
	public string $name;

	/** Decimal string, e.g. "40.00" */
	public string $price;

	/** Optional time-based pricing phases */
	public array $price_phases;

	/** int stock capacity */
	public int $capacity;

	/** ISO8601 datetime string in site timezone, or empty string for none */
	public string $sale_start;

	/** ISO8601 datetime string in site timezone, or empty string for none */
	public string $sale_end;

	/** Optional admin/customer description */
	public string $description;

	/** Optional explicit SKU */
	public string $sku;

	/** If true, do not show ticket row when sold out */
	public bool $hide_sold_out;

	/**
	 * WooCommerce product ID created/managed by ORAS-Tickets.
	 * 0 means not yet created.
	 */
	public int $product_id;

	public function __construct(array $data)
	{
		$this->ticket_key     = (string) ($data['ticket_key'] ?? '');
		$this->name           = (string) ($data['name'] ?? '');
		$this->price          = (string) ($data['price'] ?? '0.00');
		$price_phases         = $data['price_phases'] ?? [];
		if (! is_array($price_phases)) {
			$price_phases = [];
		} else {
			foreach ($price_phases as $phase) {
				if (! is_array($phase)) {
					$price_phases = [];
					break;
				}
			}
		}
		$this->price_phases   = $price_phases;
		$this->capacity       = (int) ($data['capacity'] ?? 0);
		$this->sale_start     = (string) ($data['sale_start'] ?? '');
		$this->sale_end       = (string) ($data['sale_end'] ?? '');
		$this->description    = (string) ($data['description'] ?? '');
		$this->sku            = (string) ($data['sku'] ?? '');
		$this->hide_sold_out  = (bool) ($data['hide_sold_out'] ?? false);
		$this->product_id     = (int) ($data['product_id'] ?? 0);
	}

	public function to_array(): array
	{
		return [
			'ticket_key'    => $this->ticket_key,
			'name'          => $this->name,
			'price'         => $this->price,
			'price_phases'  => $this->price_phases,
			'capacity'      => $this->capacity,
			'sale_start'    => $this->sale_start,
			'sale_end'      => $this->sale_end,
			'description'   => $this->description,
			'sku'           => $this->sku,
			'hide_sold_out' => $this->hide_sold_out,
			'product_id'    => $this->product_id,
		];
	}
}

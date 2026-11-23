<?php
/**
 * Minimal dependency-injection container.
 *
 * @package Bookora
 */

namespace Bookora\Core;

use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * Lazily builds and caches shared services.
 */
final class Container {

	/**
	 * Service factories keyed by id.
	 *
	 * @var array<string, callable>
	 */
	private array $factories = array();

	/**
	 * Built service instances.
	 *
	 * @var array<string, object>
	 */
	private array $instances = array();

	/**
	 * Register (or replace) a service factory. The Pro add-on may override services here.
	 *
	 * @param string   $id      Service id, usually the class name.
	 * @param callable $factory Receives the container, returns the service.
	 */
	public function set( string $id, callable $factory ): void {
		$this->factories[ $id ] = $factory;
		unset( $this->instances[ $id ] );
	}

	/**
	 * Whether a service is registered.
	 *
	 * @param string $id Service id.
	 */
	public function has( string $id ): bool {
		return isset( $this->factories[ $id ] );
	}

	/**
	 * Resolve a service.
	 *
	 * @param string $id Service id.
	 * @return object
	 * @throws InvalidArgumentException When the service is unknown.
	 */
	public function get( string $id ): object {
		if ( ! isset( $this->instances[ $id ] ) ) {
			if ( ! isset( $this->factories[ $id ] ) ) {
				throw new InvalidArgumentException( sprintf( 'Unknown service "%s".', $id ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
			$this->instances[ $id ] = ( $this->factories[ $id ] )( $this );
		}

		return $this->instances[ $id ];
	}
}

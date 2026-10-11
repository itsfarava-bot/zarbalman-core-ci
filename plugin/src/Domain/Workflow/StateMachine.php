<?php

declare(strict_types=1);

namespace Zarbalman\Core\Domain\Workflow;

use InvalidArgumentException;

final class StateMachine {
	/** @var list<string> */
	private array $states;

	/** @var array<string,array<string,list<string>>> */
	private array $transitions;

	/**
	 * @param list<string>                              $states
	 * @param array<string,array<string,list<string>>> $transitions
	 */
	public function __construct( array $states, array $transitions ) {
		$this->states      = array_values( array_unique( array_map( 'sanitize_key', $states ) ) );
		$this->transitions = $this->normalize_transitions( $transitions );
		$this->validate_definition();
	}

	public function has_state( string $state ): bool {
		return in_array( sanitize_key( $state ), $this->states, true );
	}

	public function can_transition( string $from, string $to, string $actor_type ): bool {
		$from       = sanitize_key( $from );
		$to         = sanitize_key( $to );
		$actor_type = sanitize_key( $actor_type );

		return isset( $this->transitions[ $from ][ $to ] )
			&& in_array( $actor_type, $this->transitions[ $from ][ $to ], true );
	}

	/** @return list<string> */
	public function next_states( string $from, ?string $actor_type = null ): array {
		$from = sanitize_key( $from );

		if ( ! isset( $this->transitions[ $from ] ) ) {
			return array();
		}

		if ( null === $actor_type ) {
			return array_keys( $this->transitions[ $from ] );
		}

		$actor_type = sanitize_key( $actor_type );

		return array_keys(
			array_filter(
				$this->transitions[ $from ],
				static fn ( array $actors ): bool => in_array( $actor_type, $actors, true )
			)
		);
	}

	/** @return list<string> */
	public function states(): array {
		return $this->states;
	}

	/** @param array<string,array<string,list<string>>> $transitions */
	private function normalize_transitions( array $transitions ): array {
		$normalized = array();

		foreach ( $transitions as $from => $targets ) {
			$from = sanitize_key( (string) $from );
			if ( ! is_array( $targets ) ) {
				continue;
			}

			foreach ( $targets as $to => $actors ) {
				if ( ! is_array( $actors ) ) {
					continue;
				}

				$normalized[ $from ][ sanitize_key( (string) $to ) ] = array_values(
					array_unique( array_map( 'sanitize_key', $actors ) )
				);
			}
		}

		return $normalized;
	}

	private function validate_definition(): void {
		foreach ( $this->transitions as $from => $targets ) {
			if ( ! $this->has_state( $from ) ) {
				throw new InvalidArgumentException( 'Workflow transition has an unknown source state.' );
			}

			foreach ( $targets as $to => $actors ) {
				if ( ! $this->has_state( $to ) ) {
					throw new InvalidArgumentException( 'Workflow transition has an unknown target state.' );
				}

				if ( array_diff( $actors, Actor::all() ) ) {
					throw new InvalidArgumentException( 'Workflow transition has an unknown actor type.' );
				}
			}
		}
	}
}


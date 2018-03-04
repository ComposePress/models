<?php

namespace ComposePress\Models\Abstracts;

use ComposePress\Core\Abstracts\Component;

/**
 * Class Model
 *
 * @package ComposePress\Models\Abstracts
 * @method int|false insert( string $table, array $data, array | string $format )
 * @method int|false update()
 * @method int|false replace( string $table, array $data, array | string $format )
 * @method int|false delete( string $table, array $data, array | string $format )
 * @method int|false insert_multisite( string $table, array $data, array | string $format )
 * @method int|false update_multisite( string $table, array $data, array $where, array | string $format, array | string $where_format )
 * @method int|false replace_multisite( string $table, array $data, array | string $format )
 * @method int|false delete_multisite( string $table, array $data, array | string $format )
 */
abstract class Model extends Component {

	/**
	 *
	 */
	const NAME = '';

	/**
	 *
	 */
	const BUILD_MODE_SINGLESITE = 'single';
	/**
	 *
	 */
	const BUILD_MODE_MULTISITE = 'multiple';
	/**
	 *
	 */
	const BUILD_MODE_MULTISITE_GLOBAL = 'single_global';

	/**
	 *
	 */
	public function init() {
		add_action( 'activate_' . plugin_basename( $this->plugin->plugin_file ), [ $this, 'build' ], 11 );
		if ( is_multisite() && ! static::get_use_multisite_global_table() ) {
			add_action( 'wpmu_new_blog', [ get_called_class(), 'build_new_blog' ] );
			add_filter( 'wpmu_drop_tables', [ get_called_class(), 'update_mu_tables' ], 10, 2 );
		}
	}

	/**
	 * @return bool
	 */
	protected function get_use_multisite_global_table() {
		return false;
	}

	/**
	 * @param $network_wide
	 */
	public function build( $network_wide ) {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$single_site      = static::get_singlesite_enabled();
		$multisite        = static::get_multisite_enabled();
		$multisite_global = static::get_use_multisite_global_table();
		if ( $single_site ) {
			dbDelta( static::build_schema( self::BUILD_MODE_SINGLESITE ) );
		}
		if ( $network_wide && $multisite && ! $multisite_global ) {
			$sites = get_sites( [ 'fields' => 'ids' ] );

			if ( 0 < count( $sites ) ) {
				foreach ( $sites as $site ) {
					switch_to_blog( $site );
					dbDelta( static::build_schema( self::BUILD_MODE_MULTISITE ) );
					restore_current_blog();
				}
			}
		}
		if ( $network_wide && $multisite && $multisite_global ) {
			dbDelta( static::build_schema( self::BUILD_MODE_MULTISITE_GLOBAL ) );
		}
	}

	/**
	 * @return bool
	 */
	protected function get_singlesite_enabled() {
		return true;
	}

	/**
	 * @return bool
	 */
	protected function get_multisite_enabled() {
		return false;
	}

	/**
	 * @param $mode
	 *
	 * @return string
	 */
	protected function build_schema( $mode ) {
		$schema_data = static::get_schema( $mode );
		$schema      = 'CREATE TABLE ' . $this->wpdb->prefix;
		if ( self::BUILD_MODE_MULTISITE_GLOBAL === $mode ) {
			$schema = $this->wpdb->base_prefix;
		}
		$schema      .= static::get_name() . ' (' . "\n";
		$field_lines = [];
		foreach ( $schema_data['fields'] as $name => $field ) {
			if ( ! empty( $field['name'] ) ) {
				$name = $field['name'];
			}
			$field_line = "{$name} {$field['type']}";

			if ( ! empty( $field['length'] ) ) {
				$field_line .= '(';
				if ( is_array( $field['length'] ) ) {
					$assoc = array_values( $field['length'] ) !== $field['length'];
					if ( $assoc ) {
						$field_line .= "{$field['length']['digits']},{$field['length']['decimals']}";
					}
					if ( ! $assoc ) {
						$field_line .= implode( ',', $field['length'] );
					}
				} else {
					$field_line .= $field['length'];
				}
				$field_line .= ')';
			}
			$field_line .= ' ';

			if ( ! empty( $field['character_set'] ) ) {
				$field_line .= "CHARACTER SET ({$field['character_set']}) ";
			}
			if ( isset( $field['default'] ) && 'NULL' === strtoupper( $field['default'] ) ) {
				$field['default'] = null;
				$field['is_null'] = true;
			}

			if ( isset( $field['default'] ) && null !== $field['default'] ) {
				$numeric    = is_numeric( $field['default'] );
				$field_line .= "DEFAULT ";
				if ( ! $numeric ) {
					$field_line .= "'";
				}
				$field_line .= $field['default'];
				if ( ! $numeric ) {
					$field_line .= "'";
				}
				$field_line .= ' ';
			}
			if ( empty( $field['is_null'] ) ) {
				$field_line .= "NOT NULL ";
			}
			$field_line    = rtrim( $field_line );
			$field_lines[] = $field_line;
		}

		if ( ! empty( $schema_data['primary_key'] ) ) {
			if ( empty( $schema_data['keys'] ) ) {
				$schema_data['keys'] = [];
			}
			$schema_data['keys'][] = [ 'primary' => true, 'columns' => $schema_data['primary_key'] ];
		}

		if ( ! empty( $schema_data['keys'] ) && is_array( $schema_data['keys'] ) ) {
			$primary_used = false;
			foreach ( $schema_data['keys'] as $key => $properties ) {
				$field_line = '';
				$assoc      = array_values( $properties ) !== $properties;
				$unique     = ! empty( $properties['unique'] );
				$primary    = ! empty( $properties['primary'] );
				if ( $primary && $primary_used ) {
					continue;
				}

				if ( $unique ) {
					$field_line .= 'UNIQUE ';
				} else if ( $primary ) {
					$field_line .= 'PRIMARY ';
				}

				if ( ! $assoc ) {
					$properties = [ 'columns' => $properties ];
				}

				$field_line .= 'KEY ';
				if ( $unique ) {
					$field_line .= "{$key} ";
				}
				$field_line .= '(' . implode( ',', (array) $properties['columns'] ) . ')';
				$field_line = rtrim( $field_line );
				if ( $primary ) {
					$primary_used = true;
				}
				$field_lines[] = $field_line;
			}
		}


		$charset_collate = $this->wpdb->get_charset_collate();

		$schema .= implode( ",\n", $field_lines ) . "\m";
		$schema .= ") {$charset_collate};";

		return $schema;
	}

	/**
	 * @param $mode
	 *
	 * @return mixed
	 */
	abstract protected function get_schema( $mode );

	/**
	 * @return string
	 */
	public function get_name() {
		return static::NAME;
	}

	/**
	 * @param $blog_id
	 */
	public function build_new_blog( $blog_id ) {
		switch_to_blog( $blog_id );
		dbDelta( static::build_schema( self::BUILD_MODE_MULTISITE ) );
		restore_current_blog();
	}

	/**
	 * @param $tables
	 * @param $blog_id
	 *
	 * @return array
	 */
	public function update_mu_tables( $tables, $blog_id ) {
		$tables[] = $this->wpdb->get_blog_prefix( $blog_id ) . static::get_name();

		return $tables;
	}

	/**
	 * @param $name
	 * @param $arguments
	 *
	 * @return mixed
	 */
	public function __call( $name, $arguments ) {
		if ( in_array( $name, [ 'insert', 'update', 'replace', 'delete' ] ) ) {
			array_unshift( $arguments, $this->wpdb->prefix . static::get_name() );

			return call_user_func_array( $this->wpdb, $arguments );
		}

		if ( in_array( $name, [ 'insert_multisite', 'update_multisite', 'replace_multisite', 'delete_multisite' ] ) ) {
			array_unshift( $arguments, $this->wpdb->base_prefix . static::get_name() );

			return call_user_func_array( $this->wpdb, $arguments );
		}

		return false;
	}

}
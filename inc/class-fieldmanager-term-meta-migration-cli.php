<?php

WP_CLI::add_command( 'fm-term-meta', 'Fieldmanager_Term_Meta_Migration_CLI' );

class Fieldmanager_Term_Meta_Migration_CLI extends WP_CLI_Command {

	/**
	 * The fm-term-meta posts found in the database.
	 *
	 * @var array
	 */
	protected $term_meta_posts;

	/**
	 * List all terms with term meta
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Accepted values: table, csv, json, count. Default: table
	 *
	 * ## EXAMPLES
	 *
	 *     wp fm-term-meta list_terms
	 *
	 * @synopsis [--format=<value>]
	 */
	public function list_terms( $args, $assoc_args ) {
		$assoc_args = wp_parse_args( $assoc_args, array(
			'format' => 'table'
		) );

		WP_CLI::warning( "Muting user-generated PHP notices for this command in order to hide deprecation notices" );
		error_reporting( error_reporting() & ~E_USER_NOTICE );

		$display = array();

		$terms = $this->get_terms_with_fm_term_meta();

		foreach ( $terms as $term ) {
			$display[] = array(
				'Post ID' => $term->post_id,
				'Taxonomy' => $term->taxonomy,
				'Term ID' => $term->term_id,
				'Term Slug' => $term->slug,
				'Term Name' => $term->name,
				'Meta Entries' => count( fm_get_term_meta( $term->term_id, $term->taxonomy ) ),
			);
		}

		\WP_CLI\Utils\format_items( $assoc_args['format'], $display, array( 'Post ID', 'Taxonomy', 'Term ID', 'Term Slug', 'Term Name', 'Meta Entries' ) );
	}

	/**
	 * Migrate all FM term meta to core term meta
	 *
	 * ## OPTIONS
	 *
	 * [--destructive]
	 * : If present, FM term meta will be deleted after it is migrated, and
	 * each FM term meta post will be deleted once its meta is migrated.
	 *
	 * [--dry-run]
	 * : If present, no updates will be made.
	 *
	 * [--verbose]
	 * : If present, script will output additional details.
	 *
	 * ## EXAMPLES
	 *
	 *     wp fm-term-meta migrate_term_meta
	 *
	 * @synopsis [--destructive] [--dry-run] [--verbose]
	 */
	public function migrate_term_meta( $args, $assoc_args ) {
		$dry_run     = ! empty( $assoc_args['dry-run'] );
		$verbose     = ! empty( $assoc_args['verbose'] );
		$destructive = ! empty( $assoc_args['destructive'] );

		WP_CLI::line( "Starting term meta migration" );
		if ( $dry_run ) {
			WP_CLI::warning( 'THIS IS A DRY RUN' );
		} elseif ( $destructive ) {
			WP_CLI::warning( 'With the --destructive flag set, this will delete all FM term meta after it is successfully migrated. There is no undo for this.' );
			WP_CLI::confirm( 'Do you want to continue?' );
		}

		if ( get_option( 'db_version' ) < 34370 ) {
			WP_CLI::error( 'This WordPress installation is not ready for term meta! You must be running WordPress 4.4 and the database update must be complete.' );
		}

		WP_CLI::warning( "Muting user-generated PHP notices for this command in order to hide deprecation notices" );
		error_reporting( error_reporting() & ~E_USER_NOTICE );

		$terms = $this->get_terms_with_fm_term_meta();
		foreach ( $terms as $term ) {
			if ( $verbose ) {
				WP_CLI::line( "Processing {$term->taxonomy} `{$term->name}' ({$term->slug}, {$term->term_id})" );
			}
			$term_meta = fm_get_term_meta( $term->term_id, $term->taxonomy );

			if ( $verbose ) {
				WP_CLI::line( sprintf( "\tFound %d meta entries", count( $term_meta ) ) );
			}

			foreach ( $term_meta as $meta_key => $meta_values ) {
				if ( $verbose ) {
					WP_CLI::line( sprintf( "\tMigrating %d meta values for meta key %s", count( $meta_values ), $meta_key ) );
				}

				$result = true;
				foreach ( $meta_values as $meta_value ) {
					if ( $dry_run || $verbose ) {
						WP_CLI::line( sprintf( "\tadd_term_meta( %d, '%s', '%s' );", $term->term_id, $meta_key, strlen( $meta_value ) < 50 ? $meta_value : '[too long to output]' ) );
					}
					if ( ! $dry_run ) {
						$this_result = add_term_meta( $term->term_id, $meta_key, $meta_value );
						if ( ! is_int( $this_result ) ) {
							$result = false;
							WP_CLI::warning( sprintf( "\tError running add_term_meta( %d, '%s', '%s' );", $term->term_id, $meta_key, $meta_value ) );
							if ( is_wp_error( $this_result ) ) {
								WP_CLI::warning( sprintf( "\t\t%s: %s", $this_result->get_error_code(), $this_result->get_error_message() ) );
							} else {
								WP_CLI::warning( sprintf( "\t\t%s", var_export( $this_result, 1 ) ) );
							}
						}
					}
				}

				if ( $destructive ) {
					if ( ! $result ) {
						WP_CLI::warning( "\tSkipping FM term meta deletion for {$meta_key} because an error was encountered while adding data" );
					} else {
						if ( $dry_run || $verbose ) {
							WP_CLI::line( "\tDeleting this term's FM term meta for {$meta_key}" );
						}
						if ( ! $dry_run ) {
							fm_delete_term_meta( $term->term_id, $term->taxonomy, $meta_key );
						}
					}
				}
			}

			if ( empty( $term_meta ) ) {
				WP_CLI::line( "\tNo FM term meta remaining for this term." );
				if ( $destructive && get_post( $term->post_id ) ) {
					if ( $verbose || $dry_run ) {
						WP_CLI::line( "\tDeleting post ID {$term->post_id}" );
					}
					if ( ! $dry_run ) {
						wp_delete_post( $term->post_id, true );
					}
				}
			}
		}

		// Print a success message
		WP_CLI::success( "Process complete!" );

		if ( ! $dry_run ) {
			WP_CLI::line( "\n" );
			WP_CLI::line( "You're almost done! To use the new term meta, you need to update Fieldmanager, then update your code accordingly:" );
			WP_CLI::line( "- Replace any call to Fieldmanager_Field::add_term_form() with Fieldmanager_Field::add_term_meta_box()." );
			WP_CLI::line( "- You need to update the arguments anywhere you're instantiating Fieldmanager_Context_Term directly." );
			WP_CLI::line( "See https://github.com/alleyinteractive/wordpress-fieldmanager/issues/400 for details." );
			WP_CLI::line( "Happy coding!" );
			WP_CLI::line( "\n" );
		}
	}

	/**
	 * Get all the term meta posts in the database.
	 *
	 * @param bool $force_update If true, forces DB query. Otherwise, the values
	 *                           from the last time this was run on this request
	 *                           will be returned.
	 * @return array post_name => ID
	 */
	protected function get_term_meta_posts( $force_update = false ) {
		global $wpdb;

		if ( $force_update || ! isset( $this->term_meta_posts ) ) {
			$results = $wpdb->get_results( "SELECT `ID`, `post_name` FROM {$wpdb->posts} WHERE `post_name` LIKE 'fm-term-meta-%'" );
			if ( ! empty( $results ) ) {
				$this->term_meta_posts = wp_list_pluck( $results, 'ID', 'post_name' );
			}
		}

		return $this->term_meta_posts;
	}

	/**
	 * Get all the terms with FM term meta.
	 *
	 * @return array Term objects.
	 */
	protected function get_terms_with_fm_term_meta() {
		$posts = $this->get_term_meta_posts();
		$terms = array();

		if ( empty( $posts ) ) {
			return array();
		}

		foreach ( $posts as $post_name => $post_id ) {
			if ( ! preg_match( '/fm-term-meta-(\d+)-(.*)/i', $post_name, $matches ) ) {
				WP_CLI::warning( "Invalid term meta post name: {$post_name}" );
				continue;
			}

			$term = get_term( intval( $matches[1] ), $matches[2] );
			if ( ! $term || is_wp_error( $term ) ) {
				WP_CLI::warning( "Term meta post found for invalid term; perhaps this was an old taxonomy? Taxonomy: {$matches[2]}, Term ID: {$matches[1]}" );
				continue;
			}

			$term->post_name = $post_name;
			$term->post_id = $post_id;
			$terms[] = $term;
		}

		return $terms;
	}

}
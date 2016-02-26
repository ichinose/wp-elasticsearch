<?php
/**
 * Plugin Name: WP Elasticsearch
 * Version: 0.1
 * Description: WordPress search replace Elasticsearch
 * Author: horike
 * Text Domain: wp-elasticsearch
 * Domain Path: /languages
 **/

require_once 'admin/option.php';
require_once 'vendor/autoload.php';

use Elastica\Client;
use Elastica\Query;
use Elastica\Query\QueryString;
use Elastica\Type\Mapping;
use Elastica\Bulk;

class WP_Elasticsearch {
	private static $instance;
	private function __construct() {}

	/**
	 * Return a singleton instance of the current class
	 *
	 * @since 0.1
	 * @return object
	 */
	public static function get_instance() {
		if ( ! isset( self::$instance ) ) {
			$c = __CLASS__;
			self::$instance = new $c();
		}
		return self::$instance;
	}

	/**
	 * Initialize.
	 *
	 * @since 0.1
	 */
	public function init() {
		add_action( 'add_option', array( $this, 'data_sync' ) );
		add_action( 'pre_get_posts', array( $this, 'pre_get_posts' ) );
		add_filter( 'wpels_search', array( $this, 'search' ) );
		add_action( 'save_post', array( $this, 'save_post' ), 10, 2 );
	}

	/**
	 * save_post action. Sync Elasticsearch.
	 *
	 * @param $post_id, $post
	 * @since 0.1
	 */
	public function save_post( $post_id, $post ) {
		if ( $post->post_type === 'product' ) {
			$ret = $this->_data_sync();
			if ( is_wp_error( $ret ) ) {
				$message = array_shift( $ret->get_error_messages( 'Elasticsearch Mapping Error' ) );
				wp_die($message);
			}
		}
	}

	/**
	 * pre_get_posts action. search replace Elasticsearch query.
	 *
	 * @param $query
	 * @since 0.1
	 */
	public function pre_get_posts( $query ) {
		if ( $query->is_search() && $query->is_main_query() ) {
			$search_query = get_search_query();
			$post_ids = apply_filters( 'wpels_search', $search_query );

			if ( ! is_wp_error( $post_ids ) ) {
				$query->set( 'post__in', $post_ids );
				$query->set( 's', '' );
			} else {
				wp_die( 'Elasticsearch Error' );
			}
		}
	}

	/**
	 * search query to Elasticsearch.
	 *
	 * @param $search_query
	 * @return true or WP_Error object
	 * @since 0.1
	 */
	public function search( $search_query ) {
		try {
			$options = get_option( 'wpels_settings' );
			$client = $this->_create_client( $options );
			if ( ! $client ) {
				throw new Exception( 'Couldn\'t make Elasticsearch Client. Parameter is not enough.' );
			}

			$type = $client->getIndex( $options['index'] )->getType( $options['type'] );
			$qs = new QueryString();
			$qs->setQuery( $search_query );
			$query_es = Query::create( $qs );
			$resultSet = $type->search( $query_es );

			$post_ids = array();
			foreach ( $resultSet as $r ) {
				$post_ids[] = $r->getID();
			}

			return $post_ids;
		} catch (Exception $e) {
			$err = new WP_Error( 'Elasticsearch Search Error', $e->getMessage() );
			return $err;
		}
	}

	/**
	 * admin_init action. mapping to Elasticsearch
	 *
	 * @since 0.1
	 */
	public function data_sync($option) {
		if ( isset( $_POST['wpels_settings']["endpoint"] ) ) {
			$ret = $this->_data_sync();
			if ( is_wp_error( $ret ) ) {
				$message = array_shift( $ret->get_error_messages( 'Elasticsearch Mapping Error' ) );
				wp_die($message);
			}
		}
	}

	/**
	 * admin_init action. mapping to Elasticsearch
	 *
	 * @return true or WP_Error object
	 * @since 0.1
	 */
	private function _data_sync() {
		try {

			$options = get_option( 'wpels_settings' );
			$client = $this->_create_client( $options );
			if ( !$client ) {
				throw new Exception( 'Couldn\'t make Elasticsearch Client. Parameter is not enough.' );
			}

			$url = parse_url(home_url());
			if ( !$url ) {
				throw new Exception( 'home_url() is disabled.' );
			}
			$index = $client->getIndex( $url['host'] );
			$index->create( array(), true );
			$type = $index->getType( 'product' );

			$mapping = array(
							'post_title' => array(
												'type' => 'string',
												'analyzer' => 'kuromoji',
											),
							'post_content' => array(
												'type' => 'string',
												'analyzer' => 'kuromoji',
											),
						);

			$type->setMapping( $mapping );
			$my_posts = get_posts( array( 'posts_per_page' => -1, 'post_type' => 'product' ) );
			$docs = array();
			foreach ( $my_posts as $p ) {
				$d = array(
					'post_title' => (string) $p->post_title,
					'post_content' => (string) strip_tags( $p->post_content ),
				);
				$docs[] = $type->createDocument( (int) $p->ID, $d );
			}
			$bulk = new Bulk( $client );
			$bulk->setType( $type );
			$bulk->addDocuments( $docs );
			$bulk->send();

			return true;
		} catch (Exception $e) {
			$err = new WP_Error( 'Elasticsearch Mapping Error', $e->getMessage() );
			return $err;
		}
	}

	/**
	 * Create connection to Elasticsearch
	 *
	 * @param $options
	 * @return Client client object
	 * @since 0.1
	 */
	private function _create_client( $options ) {
		if ( empty( $options['endpoint'] ) ) {
			return false;
		}

		$client = new \Elastica\Client( array(
			'host' => $options['endpoint'],
			'port' => 80,
		));
		return $client;
	}
}
WP_Elasticsearch::get_instance()->init();

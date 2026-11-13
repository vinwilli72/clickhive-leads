<?php
/**
 * Source capture. A small first-party cookie records where a visitor came from
 * on their first visit: landing page, referrer, UTM tags and Google click ID.
 * When they send a connected form, it is attached to the lead. No hidden
 * fields are needed on forms.
 *
 * The cookie is written by a few lines of script rather than by PHP, so it
 * still works when SiteGround serves the page from its cache.
 */

defined( 'ABSPATH' ) || exit;

const CHL_SOURCE_COOKIE = 'chl_src';

function chl_source_keys() {
	return array( 'landing', 'referrer', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'at' );
}

add_action( 'wp_head', 'chl_print_source_script', 1 );

function chl_print_source_script() {
	if ( ! apply_filters( 'chl_capture_source', true ) ) {
		return;
	}
	?>
<script id="chl-source">
(function(){try{
var n=<?php echo wp_json_encode( CHL_SOURCE_COOKIE ); ?>,d=document,l=location,q=new URLSearchParams(l.search),c={},o=null,
m=d.cookie.match(new RegExp('(?:^|; )'+n+'=([^;]*)'));
if(m){try{o=JSON.parse(decodeURIComponent(m[1]));}catch(e){o=null;}}
var k=['utm_source','utm_medium','utm_campaign','utm_term','utm_content','gclid'],paid=false;
k.forEach(function(x){var v=q.get(x);if(v){c[x]=v.slice(0,200);paid=true;}});
if(o&&!(paid&&!o.gclid&&!o.utm_source)){return;}
var r=d.referrer||'';try{if(r&&new URL(r).host===l.host){r='';}}catch(e){r='';}
c.landing=(l.origin+l.pathname+l.search).slice(0,500);c.referrer=r.slice(0,500);c.at=Math.floor(Date.now()/1000);
d.cookie=n+'='+encodeURIComponent(JSON.stringify(c))+'; path=/; max-age=7776000; SameSite=Lax'+(l.protocol==='https:'?'; Secure':'');
}catch(e){}})();
</script>
	<?php
}

/** The visitor's source from the cookie, cleaned. Empty array when there is none. */
function chl_current_source() {
	if ( empty( $_COOKIE[ CHL_SOURCE_COOKIE ] ) ) {
		return array();
	}
	$data = json_decode( wp_unslash( (string) $_COOKIE[ CHL_SOURCE_COOKIE ] ), true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	return chl_clean_source( is_array( $data ) ? $data : array() );
}

function chl_clean_source( $data ) {
	$data = is_array( $data ) ? $data : array();
	$out  = array();
	foreach ( chl_source_keys() as $key ) {
		if ( ! isset( $data[ $key ] ) || '' === (string) $data[ $key ] || is_array( $data[ $key ] ) ) {
			continue;
		}
		$v = (string) $data[ $key ];
		switch ( $key ) {
			case 'landing':
			case 'referrer':
				$v = substr( esc_url_raw( $v, array( 'http', 'https' ) ), 0, 500 );
				break;
			case 'gclid':
				$v = substr( preg_replace( '/[^A-Za-z0-9_\-]/', '', $v ), 0, 200 );
				break;
			case 'at':
				$v = absint( $v );
				break;
			default:
				$v = substr( sanitize_text_field( $v ), 0, 200 );
		}
		if ( '' !== (string) $v && 0 !== $v ) {
			$out[ $key ] = $v;
		}
	}
	return $out;
}

/** A short, literal label: "Google Ads", "google / cpc", "facebook.com" or "Direct". */
function chl_source_label( array $source ) {
	if ( ! empty( $source['gclid'] ) ) {
		return __( 'Google Ads', 'clickhive-leads' );
	}
	if ( ! empty( $source['utm_source'] ) ) {
		return $source['utm_source'] . ( ! empty( $source['utm_medium'] ) ? ' / ' . $source['utm_medium'] : '' );
	}
	if ( ! empty( $source['referrer'] ) ) {
		$host = wp_parse_url( $source['referrer'], PHP_URL_HOST );
		if ( $host ) {
			return preg_replace( '/^www\./', '', $host );
		}
	}
	return __( 'Direct', 'clickhive-leads' );
}

/** Field names and labels for the source, in display order. */
function chl_source_labels() {
	return array(
		'landing'      => __( 'Landing page', 'clickhive-leads' ),
		'referrer'     => __( 'Referrer', 'clickhive-leads' ),
		'utm_source'   => 'utm_source',
		'utm_medium'   => 'utm_medium',
		'utm_campaign' => 'utm_campaign',
		'utm_term'     => 'utm_term',
		'utm_content'  => 'utm_content',
		'gclid'        => 'gclid',
	);
}

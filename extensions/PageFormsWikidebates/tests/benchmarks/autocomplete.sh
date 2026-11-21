#!/bin/sh
# Read-only PageForms category and SMW mapping timings on a real wiki.
set -eu

ROOT="$(CDPATH= cd -- "$(dirname -- "$0")/../../../.." && pwd)"
WIKI="${PF_BENCH_WIKI:-fr}"
CATEGORY="${PF_BENCH_CATEGORY:-}"
QUERY="${PF_BENCH_QUERY:-}"
DEPTH="${PF_BENCH_DEPTH:-3}"
ROUNDS="${PF_BENCH_ROUNDS:-5}"

if [ -z "$CATEGORY" ] || [ -z "$QUERY" ]; then
	printf '%s\n' "Set PF_BENCH_CATEGORY and PF_BENCH_QUERY before running." >&2
	exit 2
fi

cd "$ROOT"
php maintenance/run.php eval --wiki="$WIKI" <<'PHP'
$category = getenv( 'PF_BENCH_CATEGORY' );
$query = getenv( 'PF_BENCH_QUERY' );
$depth = max( 1, min( 6, (int)getenv( 'PF_BENCH_DEPTH' ) ) );
$rounds = max( 1, min( 20, (int)getenv( 'PF_BENCH_ROUNDS' ) ) );
if ( $category === '' || $query === '' ) {
	echo "Missing category or query; stopping.\n";
	return;
}
$durations = [];
$count = 0;
for ( $i = 0; $i < $rounds; $i++ ) {
	$start = hrtime( true );
	$values = PFValuesUtils::getAllPagesForCategory( $category, $depth, $query );
	$durations[] = ( hrtime( true ) - $start ) / 1000000;
	$count = count( $values );
}
$sorted = $durations;
sort( $sorted, SORT_NUMERIC );
printf( "wiki=%s category=%s query=%s depth=%d results=%d runs=%d\n",
	MediaWikiMediaWikiServices::getInstance()->getMainConfig()->get( 'DBname' ),
	$category, $query, $depth, $count, $rounds );
printf( "cold=%.2f ms median=%.2f ms max=%.2f ms\n",
	$durations[0], $sorted[(int)floor( ( $rounds - 1 ) / 2 )], max( $durations ) );
$property = getenv( 'PF_BENCH_PROPERTY' );
$rawValues = getenv( 'PF_BENCH_VALUES' );
if ( $property && $rawValues ) {
	$terms = array_slice( array_map( 'trim', explode( ',', $rawValues ) ), 0, 50 );
	$start = hrtime( true );
	$mapped = PFMappingUtils::getValuesWithMappingProperty( $terms, $property );
	printf( "property=%s input=%d unique=%d elapsed=%.2f ms\n",
		$property, count( $terms ), count( $mapped ), ( hrtime( true ) - $start ) / 1000000 );
}
PHP

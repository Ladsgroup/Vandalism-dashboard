if ( window.location.protocol === 'http:' ) {
    window.location.replace( window.location.href.replace( /^http:/, "https:" ) );
}

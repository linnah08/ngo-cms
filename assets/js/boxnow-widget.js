/**
 * BoxNow locker picker widget (shared)
 *
 * Usage:
 *   BoxNowWidget.open(partnerId, function(locker) {
 *     // locker = { id, name, address, postalCode }
 *   });
 */
const BoxNowWidget = (function () {
    const TRUSTED = /^https:\/\/.*\.boxnow\..*$/;
    let _onSelect = null;
    let _iframe   = null;

    function open(partnerId, onSelect) {
        if (document.getElementById('_bnw_iframe')) return;
        _onSelect = onSelect;

        let src = 'https://widget-v5.boxnow.bg/popup.html';
        src += partnerId ? '?partnerId=' + encodeURIComponent(partnerId) + '&' : '?';
        src += 'gps=yes&autoclose=yes&autoselect=no';

        const overlay = document.createElement('div');
        overlay.id = '_bnw_overlay';
        overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9998;';
        overlay.addEventListener('click', close);

        _iframe = document.createElement('iframe');
        _iframe.id    = '_bnw_iframe';
        _iframe.src   = src;
        _iframe.allow = 'geolocation';
        _iframe.style.cssText = [
            'position:fixed', 'top:50%', 'left:50%',
            'width:min(96vw,860px)', 'height:min(92vh,680px)',
            'transform:translate(-50%,-50%)',
            'border:0', 'border-radius:16px', 'z-index:9999',
        ].join(';');

        document.body.append(overlay, _iframe);
        window.addEventListener('message', _onMessage);
    }

    function close() {
        document.getElementById('_bnw_iframe')?.remove();
        document.getElementById('_bnw_overlay')?.remove();
        _iframe   = null;
        _onSelect = null;
        window.removeEventListener('message', _onMessage);
    }

    function _onMessage(event) {
        if (!TRUSTED.test(event.origin)) return;
        const data = event.data;

        if (data === 'closeIframe' || (data && data.boxnowClose !== undefined)) {
            close();
            return;
        }

        if (!data || data.boxnowLockerId === undefined) return;

        if (typeof _onSelect === 'function') {
            _onSelect({
                id:         String(data.boxnowLockerId),
                name:       data.boxnowLockerName        ?? '',
                address:    data.boxnowLockerAddressLine1 ?? '',
                postalCode: data.boxnowLockerPostalCode   ?? '',
            });
        }

        close();
    }

    return { open, close };
})();

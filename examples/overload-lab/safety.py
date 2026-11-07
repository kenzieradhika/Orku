from ipaddress import ip_address
from socket import getaddrinfo
from urllib.parse import urlsplit


def validate_target(target: str) -> str:
    """Accept only local HTTP services; return normalized URL without trailing slash."""
    parsed = urlsplit(target)
    if parsed.scheme != 'http' or parsed.username or parsed.password or parsed.path not in ('', '/') or parsed.query or parsed.fragment:
        raise ValueError('Target must be a local HTTP URL.')
    host = parsed.hostname
    if host == 'localhost':
        if any(not ip_address(address[4][0]).is_loopback for address in getaddrinfo(host, parsed.port, type=1)):
            raise ValueError('localhost must resolve only to loopback addresses.')
    else:
        try:
            if not ip_address(host or '').is_loopback:
                raise ValueError('Target must resolve to a loopback address.')
        except ValueError as error:
            raise ValueError('Target must resolve to a loopback address.') from error
    try:
        port = parsed.port
    except ValueError as error:
        raise ValueError('Target port is invalid.') from error
    if port != 18080:
        raise ValueError('Target must use the lab port 18080.')
    authority = f'[{host}]' if ':' in (host or '') else host
    return f'http://{authority}:{port}'


def validate_config(config: dict) -> None:
    duration = config.get('duration_seconds')
    if not isinstance(duration, int) or isinstance(duration, bool) or not 1 <= duration <= 15:
        raise ValueError('duration_seconds must be an integer from 1 to 15.')

# Changelog

## 0.1.0

### New
- A map of listings on the search page, with pin clusters, a card per pin and a "Search this area" button.
- Pins for the map view load after each move, from core's search, capped and rate-limited.
- OpenFreeMap tiles by default with no key; any MapLibre style, with an optional provider key.
- With no centre set, the map zooms to fit the listings on first load; a centre set in the settings wins.
- Light and dark map styles that follow the page.
- `map_search_render()` for themes, and the `map_search_auto_place` filter to turn off the search page placement.

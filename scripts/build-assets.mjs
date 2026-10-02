// Copies the shared core next to the Blade renderer so Blade users need no Node build.
import { copyFileSync } from 'node:fs';
copyFileSync('packages/core/index.js', 'resources/dist/datagrid-core.js');
console.log('assets synced');

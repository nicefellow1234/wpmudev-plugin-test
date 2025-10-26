/**
 * Babel configuration for WPMUDEV Plugin Test
 * --------------------------------------------
 * This file tells Babel how to transform modern JavaScript (ES6+) and JSX
 * into code that browsers and WordPress can understand.
 *
 * Place this file in the project root (same level as package.json)
 * so that webpack or babel-loader can auto-detect it.
 */

module.exports = function (api) {
  // Cache the configuration for faster rebuilds.
  // Babel will re-use the cached config until environment variables change.
  api.cache(true);

  return {
    presets: [
      /**
       * @babel/preset-env
       * -----------------
       * Transpiles modern ES6+ JavaScript syntax into code that works
       * across most browsers. Automatically includes the right transformations
       * based on your target environments (via .browserslistrc or defaults).
       */
      "@babel/preset-env",

      /**
       * @babel/preset-react
       * -------------------
       * Enables JSX syntax (e.g. <div>...</div>).
       * The "runtime: 'automatic'" option removes the need
       * for `import React from 'react'` in every JSX file (React 17+).
       */
      ["@babel/preset-react", { runtime: "automatic" }]
    ]
  };
};

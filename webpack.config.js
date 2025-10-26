/* eslint-disable import/no-extraneous-dependencies */
const path = require("path");
const TerserPlugin = require("terser-webpack-plugin");
const CssMinimizerPlugin = require("css-minimizer-webpack-plugin");
const { CleanWebpackPlugin } = require("clean-webpack-plugin");
const MiniCssExtractPlugin = require("mini-css-extract-plugin");
const defaultConfig = require("@wordpress/scripts/config/webpack.config");

const isProd = process.env.NODE_ENV === "production";

if ( ! process.env.SASS_SILENCE_DEPRECATIONS ) {
  process.env.SASS_SILENCE_DEPRECATIONS = "legacy-js-api";
}

const resolveSassImplementation = () => {
  const preferEmbedded = process.env.USE_SASS_EMBEDDED === "1";

  if ( preferEmbedded ) {
    try {
      return require("sass-embedded");
    } catch (error) {
      console.warn("sass-embedded requested but not available, falling back to `sass`.");
    }
  }

  return require("sass");
};

const sassImplementation = resolveSassImplementation();

module.exports = {
  // Start from @wordpress/scripts defaults, then override
  ...defaultConfig,

  mode: isProd ? "production" : "development",

  entry: {
    drivetestpage: "./src/googledrive-page/main.jsx",
    postsmaintenance: "./src/posts-maintenance/main.jsx"
  },

  output: {
    path: path.resolve(__dirname, "assets/js"),
    filename: "[name].min.js",
    // From assets/js → we reference ../css and ../images (CSS extracted to assets/css)
    publicPath: "../../",
    assetModuleFilename: "images/[name][ext][query]"
  },

  resolve: {
    extensions: [".js", ".jsx"]
  },

  // Use WP-provided React via wp.element, etc.
  externals: {
    "@wordpress/element": ["wp", "element"],
    "@wordpress/components": ["wp", "components"],
    "@wordpress/api-fetch": ["wp", "apiFetch"],
    "@wordpress/i18n": ["wp", "i18n"]
  },

  module: {
    rules: [
      // JS/JSX via Babel (uses your babel.config.js)
      {
        test: /\.(js|jsx)$/,
        exclude: /node_modules/,
        use: { loader: "babel-loader", options: { cacheDirectory: true } }
      },

      // SCSS/CSS: dev injects styles, prod extracts + minifies
      {
        test: /\.s?css$/,
        // do NOT exclude node_modules so @wpmudev/shared-ui SCSS can be bundled
        use: [
          isProd ? MiniCssExtractPlugin.loader : "style-loader",
          {
            loader: "css-loader",
            options: { importLoaders: 2 } // postcss + sass
          },
          {
            loader: "postcss-loader" // reads postcss.config.js (autoprefixer + cssnano in prod)
          },
          {
            loader: "sass-loader",
            options: {
              // Prefer Dart Sass embedded when available, fall back to pure JS build (fixes `sass --embedded is unavailable` on some setups)
              implementation: sassImplementation,
              sassOptions: {
                // Hide deprecation noise from dependencies (e.g., @import in shared-ui)
                quietDeps: true,
                silenceDeprecations: ["legacy-js-api"]
              }
            }
          }
        ]
      },

      // Inline small SVGs
      { test: /\.svg$/, type: "asset/inline" },

      // Raster images → emit to ../images
      {
        test: /\.(png|jpe?g|gif)$/,
        type: "asset/resource",
        generator: { filename: "../images/[name][ext][query]" }
      },

      // Fonts → emit to ../fonts
      {
        test: /\.(woff2?|eot|ttf|otf)$/,
        type: "asset/resource",
        generator: { filename: "../fonts/[name][ext][query]" }
      }
    ]
  },

  plugins: [
    ...(defaultConfig.plugins || []),
    new CleanWebpackPlugin(),
    ...(isProd
      ? [new MiniCssExtractPlugin({ filename: "../css/[name].min.css" })]
      : [])
  ],

  optimization: {
    minimize: isProd,
    minimizer: [
      new TerserPlugin({
        terserOptions: { format: { comments: false } },
        extractComments: false
      }),
      ...(isProd ? [new CssMinimizerPlugin()] : [])
    ]
  },

  // Sourcemaps only in dev
  devtool: isProd ? false : "source-map",

  // Don't warn on bundle size in console
  performance: { hints: false }
};

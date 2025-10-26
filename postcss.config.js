/**
 * PostCSS config
 * - autoprefixer: add vendor prefixes for target browsers
 * - cssnano: minify CSS in production only
 */
module.exports = (ctx) => ({
  plugins: {
    autoprefixer: {},
    ...(ctx.env === "production" ? { cssnano: { preset: "default" } } : {})
  }
});

const path = require('path');
const mode = process.env.NODE_ENV === 'production' ? 'production' : 'development';

const { CleanWebpackPlugin } = require('clean-webpack-plugin');
const CopyWebpackPlugin = require('copy-webpack-plugin');
const MiniCssExtractPlugin = require('mini-css-extract-plugin');
const CssMinimizerPlugin = require('css-minimizer-webpack-plugin');
const ImageMinimizerPlugin = require("image-minimizer-webpack-plugin");

const config = {
  entry: {
    live_talkshow: [
      './js/src/live_talkshow.js',
      './js/src/scss/live_talkshow.scss'
    ],
    affiliate_talkshow_links: [
      './js/src/affiliate_talkshow_links.js',
      './js/src/scss/affiliate_talkshow_links.scss'
    ],
  },
  output: {
    path: mode === 'production' ? path.resolve(__dirname, "js/dist") : path.resolve(__dirname, "js/dist"),
    filename: 'js/[name].min.js',
    assetModuleFilename: 'images/[name][ext][query]'
  },
  devtool: mode === 'development' ? 'source-map' : false,
  mode: mode,
  resolve: {
    extensions: ['.ts', '.js', '.jsx'],
  },
  module: {
    rules: [
      {
        test: /\.html$/i,
        loader: "html-loader",
      },
      {
        test: /\.(png|jpe?g|gif)$/i,
        type: 'asset/resource',
      },
      {
        test: /\.svg$/,
        type: 'asset/inline',
      },
      {
        test: /\.(s[ac]|c)ss$/,
        use: [
          {
            loader: MiniCssExtractPlugin.loader,
          },
          {
            loader: 'css-loader',
            options: {
              url: false,
              importLoaders: 1,
            },
          },
          {
            loader: 'postcss-loader',
          },
          {
            loader: 'sass-loader',
          }
        ],
      },
      {
        test: /\.(js|jsx)$/,
        exclude: /node_modules/,
        use: {
          loader: 'babel-loader',
          options: {
            presets: [
              ['@babel/preset-env', {
                useBuiltIns: 'usage',
                corejs: 3,
              }],
            ],
          },
        },
        include: path.join(__dirname, 'js/src'),
      }
    ],
  },
  plugins: [
    new CopyWebpackPlugin({
      patterns: [
        {
          from: 'js/src/static',
          noErrorOnMissing: true,
        },
      ],
    }),
    new MiniCssExtractPlugin({
      filename: 'css/[name].min.css',
    }),
  ],
  optimization: {
    minimize: true,
    minimizer: [
      '...',
      new CssMinimizerPlugin(),
      new ImageMinimizerPlugin({
        minimizer: {
          implementation: ImageMinimizerPlugin.imageminMinify,
          options: {
            // Lossless optimization with custom option
            // Feel free to experiment with options for better result for you
            plugins: [
              ["gifsicle", { interlaced: true }],
              ["jpegtran", { progressive: true }],
              ["optipng", { optimizationLevel: 5 }],
              // Svgo configuration here https://github.com/svg/svgo#configuration
              [
                "svgo",
                {
                  plugins: [
                    {
                      name: "preset-default",
                      params: {
                        overrides: {
                          removeViewBox: false,
                          addAttributesToSVGElement: {
                            params: {
                              attributes: [
                                { xmlns: "http://www.w3.org/2000/svg" },
                              ],
                            },
                          },
                        },
                      },
                    },
                  ],
                },
              ],
            ],
          },
        },
      }),

    ],
  },
};

module.exports = config;

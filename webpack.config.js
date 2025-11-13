import path from "path";
import MiniCssExtractPlugin from "mini-css-extract-plugin";
import CssMinimizerPlugin from "css-minimizer-webpack-plugin";
import TerserPlugin from "terser-webpack-plugin";
import fs from "fs";

const isProd = process.env.NODE_ENV === "production";

// Theme source & destination paths
const theme = "ai-seo-tool";
const themePath = `web/app/themes/${theme}`;
const src = path.resolve(`${themePath}/assets/src`);
const dist = path.resolve(`${themePath}/assets/dist`);

export default {
    entry: {
        public: [
            `${src}/js/public/app.js`,
            `${src}/scss/public/app.scss`
        ],
        admin: [
            `${src}/js/admin/app.js`,
            `${src}/scss/admin/app.scss`
        ]
    },
    output: {
        path: dist,
        filename: isProd ? "js/[name].[contenthash:8].js" : "js/[name].js",
        assetModuleFilename: "assets/[name][hash:6][ext][query]",
        clean: true
    },
    devtool: isProd ? false : "source-map",
    module: {
        rules: [{
                test: /\.m?js$/,
                exclude: /(node_modules)/,
                use: {
                    loader: "babel-loader",
                    options: {
                        presets: [
                            ["@babel/preset-env", {
                                targets: "defaults"
                            }]
                        ]
                    }
                }
            },
            {
                test: /\.css$/i,
                use: [
                    MiniCssExtractPlugin.loader,
                    {
                        loader: "css-loader",
                        options: {
                            importLoaders: 1,
                            url: true
                        }
                    }
                ]
            },
            {
                test: /\.(scss|sass)$/i,
                use: [
                    MiniCssExtractPlugin.loader,
                    {
                        loader: "css-loader",
                        options: {
                            importLoaders: 2,
                            url: true
                        }
                    },
                    "postcss-loader",
                    "sass-loader"
                ]
            },
            {
                test: /\.(png|jpe?g|gif|svg|webp)$/i,
                type: "asset/resource",
                generator: {
                    filename: "images/[name][hash:6][ext]"
                }
            },
            {
                test: /\.(woff2?|ttf|otf|eot)$/i,
                type: "asset/resource",
                generator: {
                    filename: "fonts/[name][hash:6][ext]"
                }
            }
        ]
    },
    optimization: {
        minimize: isProd,
        minimizer: [new TerserPlugin({
            extractComments: false
        }), new CssMinimizerPlugin()]
    },
    plugins: [
        new MiniCssExtractPlugin({
            filename: isProd ? "css/[name].[contenthash:8].css" : "css/[name].css"
        }),
        // Simple manifest writer
        {
            apply: (compiler) => {
                compiler.hooks.done.tap("ManifestPluginLite", (stats) => {
                    const data = stats.toJson({
                        all: false,
                        assets: true,
                        assetsByChunkName: true
                    });
                    const out = {};
                    for (const [chunk, files] of Object.entries(data.assetsByChunkName || {})) {
                        (Array.isArray(files) ? files : [files]).forEach((f) => {
                            if (f.endsWith(".js")) out[`${chunk}.js`] = f;
                            if (f.endsWith(".css")) out[`${chunk}.css`] = f;
                        });
                    }
                    const file = path.join(dist, "manifest.json");
                    fs.writeFileSync(file, JSON.stringify(out, null, 2));
                });
            }
        }
    ],
    resolve: {
        extensions: [".js"],
        alias: {
            "@": src
        }
    },
    watchOptions: {
        ignored: [
            "node_modules",
            path.join(dist, "**")
        ]
    },
    stats: "minimal"
};

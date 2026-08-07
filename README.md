# 📊 Weather Model Accuracy Study

A continuous 24/7 automated analysis comparing weather model forecasts with real wind measurements from weather stations located at paragliding takeoffs.

The ultimate goal of this project is to discover which weather model performs best: overall, or specifically by parameters, locations, times, or forecast horizons. This data is openly published to help the free-flight community make better, more informed decisions.

- 🌐 **Live Dashboard:** [FlyDecision Weather Study](https://flydecision.com/estudio) 
- 🪂 **Main App:** [FlyDecision.com](https://flydecision.com)

## 🌍 Special Thanks & Data Usage

A huge shoutout and thank you to **Open-Meteo** for their fantastic API and magnificent service. Without their platform, accessing this forecast data and making this study possible would be much harder or impossible for us.

### 📊 How data is consumed:
All forecast data used in this study is extracted from the already downloaded `.json` files generated for the main FlyDecision app. This means the study itself consumes **zero additional Open-Meteo API calls**.

> **Note on ECMWF:** You might notice that ECMWF gust data is missing. We have intentionally excluded it because I am currently very close to my API credit limit. The main app works perfectly right now, and I don't want to risk exceeding the quota and disrupting the primary service.

## 🔍 What Does the Study Analyze?

The dashboard provides several views and metrics:

- **3 Models Compared:** AromeHD 1.3km / ICON-EU 7km / ECMWF IFS 9km.
- **3 Parameters:** Average wind speed, maximum gust, and wind direction.
- **4 Forecast Horizons:** +6h / +24h / +48h / +72h.
- **6 Time Slots:** Real data and forecast snapshots at 9, 11, 13, 15, 17, and 19h daily (local time Europe/Madrid).

### Key Features:
- **Overall & Parameter Ranking:** Champion score based on average ranking position across all parameters and horizons.
- **Winner Matrix:** Cross-table showing the winning model for each horizon and parameter.
- **OLS Linear Regression:** A systematic bias correction tool that calculates a calibration formula (`Actual = m * Forecast + b`) for average wind at specific takeoffs.
- **Historical Evolution:** Day-by-day error evolution charts for wind, gust, and direction.

## 📁 Project Structure

This repository contains the front-end dashboard that processes and visualizes the data.

- `index.html` - The main dashboard containing HTML, CSS, and JavaScript (Chart.js) to parse the CSV and render the tables, charts, and OLS calculations.
- `estudio.csv` - *(Ignored in this repo)* The raw data file generated automatically by the FlyDecision backend. You can view the source data [here](https://docs.google.com/spreadsheets/d/1q4gKHEBlgoGtcxPWoi5eiqz-Fm2nykaa0Z5JbAgsi18/edit). 

## 🤝 Open Source & Contributions

The code for this dashboard is completely open source. While the project is still evolving, I want to invite the community to get involved.

If you spot a bug, have an idea for a new metric, or want to improve the data visualization, your contributions are highly welcome! Feel free to:

- **Open an Issue** to report a bug or suggest a feature.
- **Submit a Pull Request** with your improvements.

## 📅 Current Status & Next Steps

The study is live and in continuous evolution. Data is being captured and accumulated daily, so the statistics will become more robust over time.

> **🌴 Vacation Notice:**
> I will be away on vacation for the next few weeks. The automated system will keep running smoothly in the background, quietly collecting data without any code changes. When I return, I will thoroughly review all feedback, study potential improvements, and apply them. It will likely take a few months of data accumulation before we have a truly statistically significant dataset to draw solid conclusions from.

**Let's make weather forecasting for free-flight more transparent and accurate together! 🌬️**
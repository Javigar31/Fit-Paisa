---
name: food-database-query
description: Skill for querying food nutrition data, comparing foods, and calculating nutritional values. Use for diet tracking, meal planning, and health analysis.
---

# Food Database Query Expert

## Core Capabilities
- **Query Nutrition**: Accurate data for 50+ common foods (expandable).
- **Food Comparison**: Side-by-side analysis of macros and micros.
- **Smart Recommendation**: Suggests foods based on "High Protein", "Low GI", "High Fiber", etc.
- **Auto Calc**: Converts portions (cups, pieces, grams) into total nutrition.

## Categorization
1. **Proteins**: Meat, Poultry, Fish, Eggs, Legumes, Dairy.
2. **Carbs**: Grains, Tubers, Fruits.
3. **Fiber**: Vegetables, Whole grains, Seeds.

## Usage Guide
- **Search**: Find foods by name (CN/EN) or aliases.
- **Filter**: "Show me low GI foods with high protein."
- **Compare**: "Compare Salmon vs Chicken Breast."
- **Record**: "User ate 2 eggs and 100g oats. Calculate totals."

## Health-Specific Guidance
- **Hypertension (DASH)**: Prioritize high Potassium, Magnesium, and low Sodium.
- **Diabetes**: Focus on low GI (<= 55) and high fiber.
- **Muscle Gain**: High protein (>= 15g/100g).

## Calculation Logic
`Total = (Nutrient_per_100g * Weight_grams) / 100`
- **Standard Portions**:
  - 1 Egg = 50g
  - 1 Cup Milk = 240ml
  - 1 Slice Bread = 30g
  - 1 Apple = 150g

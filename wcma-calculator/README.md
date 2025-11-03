# WCMA Car Classing Calculator

Modern single-page calculator application to replace the existing PHP/JavaScript calculator at wcma.ca/classing/car-classing.php.

## Features

- **Real-time calculations**: Results update automatically as users input data
- **Form validation**: Client-side validation with clear error messages
- **File upload handling**: Supports PDF, DOC, JPG, JPEG, PNG files (max 2MB)
- **Responsive design**: Works seamlessly on desktop, tablet, and mobile devices
- **Accessibility**: WCAG 2.1 AA compliant with keyboard navigation and screen reader support
- **Print functionality**: Clean print layout for saving results

## File Structure

```
wcma-calculator/
├── car-classing.html          # Main HTML file
├── css/
│   └── calculator.css        # Stylesheet
├── js/
│   ├── calculator.js         # Calculation logic
│   ├── form-handler.js       # Form validation and submission
│   └── ui-controller.js      # DOM manipulation and event handling
└── README.md                 # This file
```

## Installation

1. Upload all files to your web server maintaining the directory structure
2. Ensure the form action points to your existing PHP backend endpoint
3. The calculator should work immediately with modern browsers

## Usage

1. Fill in required contact information (Name, Email, Year, Make, Model)
2. Optionally upload supporting documents (dyno charts, images)
3. Enter base information (Competition Weight, Declared HP)
4. Select modification factors (enabled after base info is entered)
5. View real-time calculation results
6. Select target class and submit form

## Calculation Logic

The calculator determines car classes based on weight/horsepower ratios:

- **GTU**: < 6.00
- **GT1**: 6.00 - 7.99
- **GT2**: 8.00 - 9.99
- **GT3**: 10.00 - 11.99
- **GT4**: 12.00 - 13.99
- **IT1**: 14.00 - 17.99
- **IT2**: >= 18.00

### Formula

1. Base Ratio = Competition Weight (lbs) / Declared HP
2. Modification Factor = Sum of all selected modification factors
3. Modified Ratio = Base Ratio + Modification Factor
4. Class = Determined from Modified Ratio using ranges above

**Note**: The weight factor calculation in `calculator.js` may need adjustment based on actual WCMA rules. Currently, it uses a multiplier based on the target class, but the exact formula should be verified against WCMA regulations.

## Browser Compatibility

- Chrome (last 2 versions)
- Firefox (last 2 versions)
- Safari (last 2 versions)
- Edge (last 2 versions)

Requires ES6+ support (modules, arrow functions, const/let).

## Form Submission

The form submits to the existing PHP backend endpoint specified in the form action attribute. The form maintains compatibility with the existing backend by:

- Using the same field names
- Supporting multipart/form-data for file uploads
- Including all form data in the submission

## Customization

### Styling

Colors and spacing can be customized via CSS custom properties in `css/calculator.css`:

```css
:root {
    --primary-color: #2c3e50;
    --secondary-color: #3498db;
    --error-color: #e74c3c;
    /* ... */
}
```

### Calculation Formula

To adjust the calculation formula, modify the functions in `js/calculator.js`:

- `calculateWeightFactor()` - Adjust weight factor calculation
- `calculateModifiedRatio()` - Adjust how modification factors are applied
- `determineClass()` - Adjust class ranges if needed

## Notes

- Modification factor dropdowns are disabled until base information (weight and HP) is entered
- File uploads are validated for type and size before submission
- Form validation provides real-time feedback as users interact with fields
- The calculator performs client-side calculations only; final validation should be done server-side

## Future Enhancements

- Drag-and-drop file upload
- Save form data to localStorage
- Export results as PDF
- Integration with WCMA database for automatic class verification


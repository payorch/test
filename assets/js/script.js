
var script = document.createElement('script');
script.src = 'https://www.merchant.geidea.net/hpp/geideaCheckout.min.js';

// Add a callback function to execute when the script is loaded
// script.onload = callback;

// Append the script element to the document
document.head.appendChild(script);

var y_offsetWhenScrollDisabled = 0;



function disableScrollOnBody() {
    y_offsetWhenScrollDisabled = jQuery(window).scrollTop();
    jQuery('body').addClass('scrollDisabled').css('margin-top', -y_offsetWhenScrollDisabled);
}

function enableScrollOnBody() {
    jQuery('body').removeClass('scrollDisabled').css('margin-top', 0);
    jQuery(window).scrollTop(y_offsetWhenScrollDisabled);
}

function createAndStartPayment(data) {
    // Create a new XMLHttpRequest object
    var xhr = new XMLHttpRequest();
    // Set up the request
    xhr.open('POST', '../../geidea/session.php');
    xhr.setRequestHeader('Content-Type', 'application/json');

    // Set up a function to handle the response
    xhr.onload = function () {
        if (xhr.status === 200) {
            // Get the response text
            var response = xhr.responseText.trim();

            // Check if the response is empty or not
            if (response.length > 0) {
                // Start the payment with the session ID
                startPayment(response, data);
            } else {
                alert('Error: Empty response from server');
            }
        } else {
            alert('Error: ' + xhr.statusText);
        }
    };

    // Set up a function to handle network errors
    xhr.onerror = function () {
        alert('Error: Network Error');
    };



    // Send the request with the JSON string
    // var data = {
    //     amount: 10.00,
    //     currency: "EGP",
    //     // callbackUrl: "https://webhook.site/cd1f0358-4088-4d75-866e-5e5eba5e45fd",
    //     callbackUrl: "https://webhook.site/2b43b8ae-e4c8-4b36-9d84-ea99ecfa8b79",
    //     merchantReferenceId: "geidea-123",
    //     language: "en",
    //     customer: {
    //         email: "kanti.kiran@payorch.com"
    //     },

    //     appearance: {
    //         showEmail: true
    //     }
    // };


    xhr.send(JSON.stringify(data));
}


function startPayment(sessionId, data) {

    var billingAddress = JSON.parse(data.billingAddress);
    var shippingAddress = JSON.parse(data.shippingAddress);
    
    data = {
                callbackUrl: data.callbackUrl,
                amount: parseFloat(data.amount),
                currency: data.currencyId,
                merchantReferenceId: data.orderId.toString(),
                cardOnFile: Boolean(data.cardOnFile),
                initiatedBy: "Internet",
                email:{
                    showEmail:(data.showEmail==='yes')?true:false,
                    customerEmail: data.customerEmail
                    },
                showPhone: (data.showPhone === 'yes') ? true : false,
                customerPhoneNumber: data.customerPhoneNumber,
                address: {
                    showAddress: (data.showAddress==='yes')?true:false,
                    billing: billingAddress,
                    shipping: shippingAddress
                },
                merchantLogoUrl: data.merchantLogoUrl,
                language: data.language,
                styles: { "headerColor": data.headerColor },
                integrationType: data.integrationType,
                name: data.name,
                version: data.version,
                pluginVersion: data.pluginVersion,
                partnerId: data.partnerId,
                isTransactionReceiptEnabled: data.receiptEnabled === "yes"
            };
    // Start the payment
    var payment = new GeideaCheckout(data, onSuccess, onError, onCancel);
    //payment.configurePayment(data);
    payment.startPayment(sessionId, data);
}
// Define the onSuccess function
let onSuccess = function (data) {
    alert('Success:' + '\n' +
        data.responseCode + '\n' +
        data.responseMessage + '\n' +
        data.detailedResponseCode + '\n' +
        data.detailedResponseMessage + '\n' +
        data.orderId + '\n' +
        data.reference);
};

// Define the onError function
let onError = function (data) {
    alert('Error:' + '\n' +
        data.responseCode + '\n' +
        data.responseMessage + '\n' +
        data.detailedResponseCode + '\n' +
        data.detailedResponseMessage + '\n' +
        data.orderId + '\n' +
        data.reference);
};

// Define the onCancel function
let onCancel = function (data) {
    alert('Payment Cancelled:' + '\n' +
        data.responseCode + '\n' +
        data.responseMessage + '\n' +
        data.detailedResponseCode + '\n' +
        data.detailedResponseMessage + '\n' +
        data.orderId + '\n' +
        data.reference);
};

function initGIPaymentOnCheckoutPage(data) {
    disableScrollOnBody();
    try {


        // var billingAddress = JSON.parse(data.billingAddress);
        // var shippingAddress = JSON.parse(data.shippingAddress);

        //   var api = new GeideaApi(data.merchantGatewayKey, onSuccess, onError, onCancel);
        //     api.configurePayment({
        //         callbackUrl: data.callbackUrl,
        //         amount: parseFloat(data.amount),
        //         currency: data.currencyId,
        //         merchantReferenceId: data.orderId.toString(),
        //         cardOnFile: Boolean(data.cardOnFile),
        //         initiatedBy: "Internet",
        //         email:{
        //             showEmail:(data.showEmail==='yes')?true:false,
        //             customerEmail: data.customerEmail
        //             },
        //         showPhone: (data.showPhone === 'yes') ? true : false,
        //         customerPhoneNumber: data.customerPhoneNumber,
        //         address: {
        //             showAddress: (data.showAddress==='yes')?true:false,
        //             billing: billingAddress,
        //             shipping: shippingAddress
        //         },
        //         merchantLogoUrl: data.merchantLogoUrl,
        //         language: data.language,
        //         styles: { "headerColor": data.headerColor },
        //         integrationType: data.integrationType,
        //         name: data.name,
        //         version: data.version,
        //         pluginVersion: data.pluginVersion,
        //         partnerId: data.partnerId,
        //         isTransactionReceiptEnabled: data.receiptEnabled === "yes"
        //     });
        //     api.startPayment();

        createAndStartPayment(data);

    } catch (err) {
        enableScrollOnBody();
        alert(err);
    }

    console.log("checkout");
    // header := w.Header()
    // header.Add("Access-Control-Allow-Origin", "*")
    // header.Add("Access-Control-Allow-Methods", "DELETE, POST, GET, OPTIONS")
    // header.Add("Access-Control-Allow-Headers", "Content-Type, Authorization, X-Requested-With")
}
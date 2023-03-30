var y_offsetWhenScrollDisabled = 0;

function disableScrollOnBody() {
    y_offsetWhenScrollDisabled = jQuery(window).scrollTop();
    jQuery('body').addClass('scrollDisabled').css('margin-top', -y_offsetWhenScrollDisabled);
}

function enableScrollOnBody() {
    jQuery('body').removeClass('scrollDisabled').css('margin-top', 0);
    jQuery(window).scrollTop(y_offsetWhenScrollDisabled);
}

var onSuccess = function(_message, _statusCode) {
    setTimeout(document.location.href = data.successUrl, 1000);
}

var onError = function(error) {
    enableScrollOnBody();
    jQuery("#place_order").removeAttr("disabled");
    console.log(error);
    // alert("Geidea Payment Gateway error: " + error.responseMessage);
}

var onCancel = function() {
    enableScrollOnBody();
    jQuery("#place_order").removeAttr("disabled");
}

function initGIPaymentOnCheckoutPage(data) {
    disableScrollOnBody();
    startV2HPP(data);
}

const startV2HPP = (data) => {
    const iframeConfiguration = getIframeConfiguration(data);
    createSession(iframeConfiguration)
        .then(({data, error }) => {
            if (error) {
                throw error
            }
            if (data.responseCode !== '000') {
                throw data
            }
            const api = new GeideaCheckout(onSuccess, onError, onCancel);
            api.startPayment(data.session.id)
            console.log("crossed")
        })
        .catch((error) => {
            let receivedError = {};
            const errorFields = [];

            if (error.status && error.errors) {
                const errorsObject = error.errors;

                for (const key of Object.keys(errorsObject)) {
                    errorFields.push(key.replace('$.', ''))
                }

                receivedError = {
                    responseCode: '100',
                    responseMessage: 'Field formatting errors',
                    detailResponseMessage: `Fields with errors: ${errorFields.toString()}`,
                    reference: error.reference,
                    detailResponseCode: null,
                    orderId: null
                }
            } else {
                receivedError = {
                    responseCode: error.responseCode,
                    responseMessage: error.responseMessage,
                    detailResponseMessage: error.detailedResponseMessage,
                    detailResponseCode: error.detailedResponseCode,
                    orderId: null,
                    reference: error.reference,
                }
            }
            onError(receivedError);
        })
}

const getIframeConfiguration = (data) => {
    return {
        merchantPublicKey: data.merchantGatewayKey,
        apiPassword: data.merchantPassword,
        callbackUrl: data.callbackUrl,
        amount: parseFloat(data.amount),
        currency: data.currencyId,
        merchantReferenceId: data.orderId.toString(),
        cardOnFile: Boolean(data.cardOnFile),
        initiatedBy: "Internet",
        customer: {
            email: data.customerEmail,
            phoneNumber: data.customerPhoneNumber,
            address: {
                billing: JSON.parse(data.billingAddress),
                shipping: JSON.parse(data.shippingAddress),
            },
        },
        appearance: {
            merchant: {
                logoUrl: data.merchantLogoUrl,
            },
            showEmail: (data.showEmail === 'yes') ? true : false,
            showAddress: (data.showAddress === 'yes') ? true : false,
            showPhone: (data.showPhone === 'yes') ? true : false,
            receiptPage: (data.receiptEnabled === "yes"),
            styles: {
             "headerColor": data.headerColor,
             "hideGeideaLogo": data.hideGeideaLogo === 'yes'
            },
        },
        language: data.language,
        integrationType: data.integrationType,
        name: data.name,
        version: data.version,
        pluginVersion: data.pluginVersion,
        partnerId: data.partnerId,
    }
};

// CONCATENATED MODULE: ./src/environments.js
const gatewayApi = {
    dev: 'https://api-dev.gd-azure-dev.net',
    test: 'https://api-test.gd-azure-dev.net',
    preprod: 'https://api.gd-pprod-infra.net',
    prod: 'https://api.merchant.geidea.net',
};


// CONCATENATED MODULE: ./src/settings.js
const paymentIntentBaseURL = {
    local: `${gatewayApi.dev}/payment-intent`,
    dev: `${gatewayApi.dev}/payment-intent`,
    test: `${gatewayApi.test}/payment-intent`,
    preprod: `${gatewayApi.preprod}/payment-intent`,
    prod: `${gatewayApi.prod}/payment-intent`,
};


/* harmony default export */ var settings = ({
    PaymentIntentBaseURL: paymentIntentBaseURL["prod"]
});

// CONCATENATED MODULE: ./src/api.js
const onReadyStateChangeHandler = (xhttp, callback) => {
    if (xhttp.readyState === 4) {
        const reference = xhttp.getResponseHeader('X-Correlation-ID');

        if (xhttp.status === 200) {
            const okData = {
                status: xhttp.status,
            };

            try {
                const result = JSON.parse(xhttp.responseText);
                okData.data = { ...result, reference };
            } catch (parseError) {
                okData.data = xhttp.responseText;
            }

            callback(okData);
        } else {
            const errorData = {
                status: xhttp.status,
                error: true,
            };

            // if error is json parse it to the error property
            if (xhttp.responseText) {
                try {
                    const error = JSON.parse(xhttp.responseText);
                    errorData.error = { ...error, reference };
                } catch (parseError) {
                    errorData.error = xhttp.responseText;
                }
            }

            callback({ ...errorData, reference });
        }
    }
};

const makeHttpRequest = (request, headers, callback) => {
    const xhttp = new XMLHttpRequest();
    if (callback) {
        xhttp.onreadystatechange = () => {
            onReadyStateChangeHandler(xhttp, callback);
        };
    }
    xhttp.open(request.method, request.url, true);
    xhttp.setRequestHeader('Content-Type', 'application/json;charset=UTF-8');

    if (headers) {
        xhttp.setRequestHeader('authorization', `Basic ${headers.authentication}`)
    }
    xhttp.send(JSON.stringify(request.data) || '');
};

const makeRequest = (request, headers) =>
    new Promise((resolve) =>
        makeHttpRequest(request, headers, (response) => {
            resolve(response);
        })
    );

const createSession = (payload) => {
    const authentication = window.btoa(`${payload.merchantPublicKey}:${payload.apiPassword}`);
    return makeRequest({
        url: `${settings.PaymentIntentBaseURL}/api/v1/direct/session`,
        method: 'POST',
        data: payload,
        excludeSource: true,
    },
        { authentication }
    );
}
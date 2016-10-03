function DataTableImportCsvController($scope, $uibModalInstance, FileUploader, $http, $uibModal, object) {
    $scope.importCsvPath = Paths.DATA_TABLE_IMPORT_CSV;

    $scope.object = object;
    $scope.item = null;
    $scope.restructure = false;
    $scope.headerRow = false;
    $scope.delimiter = ",";
    $scope.enclosure = '"';

    $scope.getFileName = function () {
        return $scope.item.file.name;
    };

    $scope.uploader = new FileUploader({
        autoUpload: true,
        url: Paths.FILE_UPLOAD
    });

    $scope.uploader.onCompleteItem = function (item, response, status, headers) {
        if (response.result === 0) {
            $scope.item = item;
        } else {
            $scope.showErrorAlert();
        }
    };


    $scope.save = function () {
        $http.post($scope.importCsvPath.pf($scope.object.id, $scope.restructure ? 1 : 0, $scope.headerRow ? 1 : 0, $scope.delimiter, $scope.enclosure), {
            file: $scope.item.file.name
        }).success(function (response) {
            if (response.result === 0) {
                $uibModal.open({
                    templateUrl: Paths.DIALOG_TEMPLATE_ROOT + 'alert_dialog.html',
                    controller: AlertController,
                    size: "sm",
                    resolve: {
                        title: function () {
                            return Trans.DATA_TABLE_IO_DIALOG_TITLE_IMPORT;
                        },
                        content: function () {
                            return Trans.DATA_TABLE_IO_DIALOG_MESSAGE_IMPORTED;
                        },
                        type: function () {
                            return "success";
                        }
                    }
                });
            } else {
                $uibModal.open({
                    templateUrl: Paths.DIALOG_TEMPLATE_ROOT + 'alert_dialog.html',
                    controller: AlertController,
                    size: "sm",
                    resolve: {
                        title: function () {
                            return Trans.DATA_TABLE_IO_DIALOG_TITLE_IMPORT;
                        },
                        content: function () {
                            return response.errors[0];
                        },
                        type: function () {
                            return "danger";
                        }
                    }
                });
            }
            $uibModalInstance.close($scope.item.file.name);
        }).error(function (data, status, headers, config) {
            $scope.showErrorAlert();
        });
    };

    $scope.showErrorAlert = function () {
        $uibModal.open({
            templateUrl: Paths.DIALOG_TEMPLATE_ROOT + 'alert_dialog.html',
            controller: AlertController,
            size: "sm",
            resolve: {
                title: function () {
                    return Trans.DATA_TABLE_IO_DIALOG_TITLE_IMPORT;
                },
                content: function () {
                    return Trans.DATA_TABLE_IO_DIALOG_MESSAGE_ERROR;
                },
                type: function () {
                    return "danger";
                }
            }
        });
        $uibModalInstance.dismiss(0);
    };

    $scope.cancel = function () {
        $uibModalInstance.dismiss(0);
    };
}

class Destino {
  final int id;
  final int? destinoId;
  final String razonSocial;
  final String sucursal;
  final String direccion;
  final int orden;
  final String estatus;
  final bool completado;

  Destino({
    required this.id,
    this.destinoId,
    required this.razonSocial,
    required this.sucursal,
    required this.direccion,
    required this.orden,
    this.estatus = 'programada',
    this.completado = false,
  });

  Destino copyWith({
    int? id,
    int? destinoId,
    String? razonSocial,
    String? sucursal,
    String? direccion,
    int? orden,
    String? estatus,
    bool? completado,
  }) {
    return Destino(
      id: id ?? this.id,
      destinoId: destinoId ?? this.destinoId,
      razonSocial: razonSocial ?? this.razonSocial,
      sucursal: sucursal ?? this.sucursal,
      direccion: direccion ?? this.direccion,
      orden: orden ?? this.orden,
      estatus: estatus ?? this.estatus,
      completado: completado ?? this.completado,
    );
  }

  factory Destino.fromJson(Map<String, dynamic> json) {
    try {
      final strEstatus = json['estatus']?.toString().toLowerCase().trim() ?? 'programada';
      final isCompletado = json['completado'] == true || 
                          json['completada'] == 1 || 
                          strEstatus == 'completado' || 
                          strEstatus == 'completada';

      final String nombreFinal = (json['razon_social'] != null && json['razon_social'].toString().isNotEmpty)
          ? json['razon_social'].toString()
          : (json['destino_manual']?.toString() ?? 'Destino');

      return Destino(
        id: _toInt(json['id']),
        destinoId: json['destino_id'] != null ? _toInt(json['destino_id']) : null,
        razonSocial: nombreFinal,
        sucursal: json['sucursal']?.toString() ?? '',
        direccion: json['direccion']?.toString() ?? '',
        orden: _toInt(json['orden']),
        estatus: strEstatus,
        completado: isCompletado,
      );
    } catch (e) {
      rethrow;
    }
  }

  Map<String, dynamic> toJson() {
    return {
      'id': id,
      'destino_id': destinoId,
      'razon_social': razonSocial,
      'sucursal': sucursal,
      'direccion': direccion,
      'orden': orden,
      'estatus': estatus,
      'completado': completado,
    };
  }
}

class RutaProgramada {
  final int id;
  final String numeroRuta;
  final String chofer;
  final String? auxiliar;
  final String placas;
  final String noEconomico;
  final String origen;
  final String? destinoFinal;
  final DateTime fechaInicio;
  final DateTime? fechaFin;
  final double kmInicial;
  final double? kmFinal;
  final double? kmTotal;
  final double totalGastos;
  final int numeroParadas;
  final String estatus;
  final List<Destino> destinos;

  RutaProgramada({
    required this.id,
    required this.numeroRuta,
    required this.chofer,
    this.auxiliar,
    required this.placas,
    required this.noEconomico,
    required this.origen,
    this.destinoFinal,
    required this.fechaInicio,
    this.fechaFin,
    required this.kmInicial,
    this.kmFinal,
    this.kmTotal,
    required this.totalGastos,
    required this.numeroParadas,
    required this.estatus,
    required this.destinos,
  });

  RutaProgramada copyWith({
    int? id,
    String? numeroRuta,
    String? chofer,
    String? auxiliar,
    String? placas,
    String? noEconomico,
    String? origen,
    String? destinoFinal,
    DateTime? fechaInicio,
    DateTime? fechaFin,
    double? kmInicial,
    double? kmFinal,
    double? kmTotal,
    double? totalGastos,
    int? numeroParadas,
    String? estatus,
    List<Destino>? destinos,
  }) {
    return RutaProgramada(
      id: id ?? this.id,
      numeroRuta: numeroRuta ?? this.numeroRuta,
      chofer: chofer ?? this.chofer,
      auxiliar: auxiliar ?? this.auxiliar,
      placas: placas ?? this.placas,
      noEconomico: noEconomico ?? this.noEconomico,
      origen: origen ?? this.origen,
      destinoFinal: destinoFinal ?? this.destinoFinal,
      fechaInicio: fechaInicio ?? this.fechaInicio,
      fechaFin: fechaFin ?? this.fechaFin,
      kmInicial: kmInicial ?? this.kmInicial,
      kmFinal: kmFinal ?? this.kmFinal,
      kmTotal: kmTotal ?? this.kmTotal,
      totalGastos: totalGastos ?? this.totalGastos,
      numeroParadas: numeroParadas ?? this.numeroParadas,
      estatus: estatus ?? this.estatus,
      destinos: destinos ?? this.destinos,
    );
  }

  factory RutaProgramada.fromJson(Map<String, dynamic> json) {
    try {
      final Map<String, dynamic> data = json.containsKey('ruta') && json['ruta'] is Map
          ? json['ruta']
          : (json.containsKey('data') && json['data'] is Map ? json['data'] : json);

      final rawDestinos = data['destinos'] ?? 
                          data['paradas'] ?? 
                          data['lista_destinos'] ?? 
                          data['detalles']; 

      final destinosList = <Destino>[];
      
      if (rawDestinos != null && rawDestinos is List) {
        for (var d in rawDestinos) {
          try {
            destinosList.add(Destino.fromJson(d));
          } catch (e) {}
        }
      }

      return RutaProgramada(
        id: _toInt(data['id']),
        numeroRuta: data['numero_ruta']?.toString() ?? '',
        chofer: data['chofer']?.toString() ?? '',
        auxiliar: data['auxiliar']?.toString(),
        placas: data['placas']?.toString() ?? '',
        noEconomico: data['no_economico']?.toString() ?? data['numero_economico']?.toString() ?? '',
        origen: data['origen']?.toString() ?? 'Pendiente',
        destinoFinal: data['destino_final']?.toString(),
        fechaInicio: _toDateTime(data['fecha_inicio']),
        fechaFin: data['fecha_fin'] != null ? _toDateTime(data['fecha_fin']) : null,
        kmInicial: _toDouble(data['km_inicial']),
        kmFinal: data['km_final'] != null ? _toDouble(data['km_final']) : null,
        kmTotal: data['km_total'] != null ? _toDouble(data['km_total']) : null,
        totalGastos: _toDouble(data['total_gastos']),
        numeroParadas: _toInt(data['numero_paradas']),
        estatus: data['estatus']?.toString() ?? 'programada',
        destinos: destinosList,
      );
    } catch (e) {
      rethrow;
    }
  }

  Map<String, dynamic> toJson() {
    return {
      'id': id,
      'numero_ruta': numeroRuta,
      'chofer': chofer,
      'auxiliar': auxiliar,
      'placas': placas,
      'no_economico': noEconomico,
      'origen': origen,
      'destino_final': destinoFinal,
      'fecha_inicio': fechaInicio.toIso8601String(),
      'fecha_fin': fechaFin?.toIso8601String(),
      'km_inicial': kmInicial,
      'km_final': kmFinal,
      'km_total': kmTotal,
      'total_gastos': totalGastos,
      'numero_paradas': numeroParadas,
      'estatus': estatus,
      'destinos': destinos.map((d) => d.toJson()).toList(),
    };
  }
}

int _toInt(dynamic value) {
  if (value == null) return 0;
  if (value is int) return value;
  if (value is num) return value.toInt();
  if (value is String) return int.tryParse(value) ?? 0;
  return 0;
}

double _toDouble(dynamic value) {
  if (value == null) return 0.0;
  if (value is double) return value;
  if (value is num) return value.toDouble();
  if (value is String) return double.tryParse(value) ?? 0.0;
  return 0.0;
}

DateTime _toDateTime(dynamic value) {
  if (value == null) return DateTime.now();
  if (value is DateTime) return value;
  if (value is String) {
    try {
      return DateTime.parse(value);
    } catch (e) {
      return DateTime.now();
    }
  }
  return DateTime.now();
}
